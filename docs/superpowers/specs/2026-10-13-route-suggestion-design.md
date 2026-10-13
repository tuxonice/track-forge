# Route Suggestion Design

## Goal

A user selects 2 or more of their saved tracks. The system finds where those tracks cross or come close to each other, builds a few candidate routes by following one track and switching to another at those points, and shows them ranked. The user can save one as a new track.

## Requirements (agreed)

- Input: 2–N of the current user's own tracks (N = `ROUTE_MAX_TRACKS`, default 6).
- A route may switch tracks at a crossing. A crossing is a geometric intersection or a point where tracks come within the tolerance (default 25 m). Proximity switches insert a straight connector between the two tracks.
- A route starts at an endpoint (start or end) of one selected track and ends at an endpoint of a selected track. Starting or ending mid-track is out of scope.
- A route never reuses an edge (a piece of track between two nodes) and never rides an edge against the direction it was entered.
- A route must ride at least one edge of every selected track.
- Ranking: longest distance first. Return the top `ROUTE_MAX_SUGGESTIONS` (default 5).
- Saving a suggestion creates a normal GPX file and `Track` record, so the existing map, stats and share links work on it.

## Approach

Graph plus exhaustive path enumeration (depth-first search with caps). It is deterministic, exact for small inputs and needs no external services. Rejected alternatives: a greedy walk (too few routes, misses good ones) and an external routing service (outside dependency, leaks location data, overkill for a self-hosted app).

## Components

New code in `src/Gps/Route/`. It is pure PHP with no Symfony or Doctrine dependencies.

- `CrossingDetector`
  - Input: point lists of the selected tracks.
  - Finds crossings between each pair of tracks by segment intersection and by proximity within the tolerance, using a coarse spatial grid to avoid comparing every point with every other point.
  - A stretch where two tracks run together collapses to one crossing at its entry and one at its exit.
  - Self-crossings of a single track are ignored.
  - Output: crossings with the position along track A and along track B.
- `TrackGraphBuilder`
  - Splits each track at its crossing positions.
  - Output: a graph. Nodes are track endpoints and crossings. Edges are track pieces between consecutive nodes (source track, point slice, distance). Proximity crossings get a connector edge.
- `RouteFinder`
  - Depth-first search from each endpoint node under the rules above.
  - Accepts only routes that end at an endpoint and cover every selected track.
  - A step cap (`ROUTE_SEARCH_STEP_CAP`, default 100000) stops the search on pathological inputs.
  - Output: top N routes sorted by distance.
- `GpxExporter`
  - Writes a chosen route's points as GPX using `sibyx/phpgpx`.
  - The output must pass `GpxValidator` and parse through `GpsTrack`.
- `RouteSuggestionService`
  - Used by the controller.
  - Loads stored GPX files through `GpsTrack`, then runs detector, builder and finder.
  - Applies the track and point limits.

## Data flow and UI

1. The track list (`app_tracks`) gets checkboxes and a "Suggest route" button, enabled when 2 or more tracks are selected.
2. `RouteSuggestionController` handles `POST /en/routes/suggest` and `POST /pt/rotas/sugerir`. The route is locale-keyed like the other user-facing pages. The request is CSRF-protected. The controller checks that every selected track belongs to the current user, then renders up to 5 suggestions. Each shows distance, elevation gain, the tracks used and a map preview.
3. Suggestions are computed on the fly and not stored.
4. "Save as track" posts `trackKeys` and the chosen route index to a single, unprefixed POST endpoint. The server recomputes the suggestions, takes that route and writes it with `FileUploaderService` under `var/data/profile-{userId}/`. It creates a `Track` and computes stats as an upload does, then redirects to the new track's info page.
5. The client never sends coordinates, so a user cannot submit an arbitrary file through this path.

## Error and edge cases

- No valid route (tracks never cross, or no route covers every track): the page says so and explains why.
- More than `ROUTE_MAX_TRACKS` tracks, or a track over the point cap: rejected with a message.
- Empty tracks: rejected.
- Route-only (`<rte>`) tracks are handled like `<trk>` tracks.
- Another user's track, missing CSRF token, or fewer than 2 tracks: rejected (403/404 as appropriate).

## i18n

New keys in `translations/messages.{en,pt}.yaml` for the button, results page, flash message and no-route message. The save endpoint's flash message uses `User::locale`, following `/track/upload` and `/track/delete`. The default saved name is "Suggested route – {date}".

## Configuration

Bound in `config/services.yaml`, sourced from `.env.dist`:

- `ROUTE_CROSSING_TOLERANCE_M` (25)
- `ROUTE_MAX_SUGGESTIONS` (5)
- `ROUTE_MAX_TRACKS` (6)
- `ROUTE_SEARCH_STEP_CAP` (100000)

## Testing

Test-first. `phpcs` and `phpstan` (level 6) must stay clean.

Unit (`tests/Unit/Gps/Route/`), using small synthetic point lists:
- `CrossingDetector`: an X gives one crossing. Parallel tracks farther apart than the tolerance give none. A shared stretch gives an entry and an exit crossing only. Self-crossings are ignored.
- `TrackGraphBuilder`: node and edge counts are right, and edge distances sum to the original track length.
- `RouteFinder`: routes that skip a track are excluded, no edge is reused, results are sorted by distance, the step cap holds, and three tracks crossing in a triangle give the expected set.
- `GpxExporter`: the output parses back through `GpsTrack` and `GpxValidator` with the expected point count.

Functional (`tests/Functional/`, on `WebTestCase`):
- Suggesting from 2 own tracks works.
- Another user's track is rejected.
- A missing CSRF token is rejected.
- Fewer than 2 tracks is rejected.
- Saving creates a `Track`, a file on disk and correct stats.
- The no-route page renders.

New crossing GPX fixtures go in `tests/Fixtures/gpx/` if the existing ones do not intersect.

## Out of scope

- Storing suggestions.
- Starting or ending mid-track, and closed loops.
- Road snapping.
- Recomputing elevation. It is copied from the source points.
- Timestamps. Stitched pieces have no coherent timeline, so suggested tracks have no velocity chart.
