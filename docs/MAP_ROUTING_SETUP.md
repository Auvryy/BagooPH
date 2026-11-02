# Map and road-routing setup

The saved-pin and road-routing changes are local feature-branch work until
reviewed, merged and deployed. A street map and a road route are separate:
OpenStreetMap supplies map tiles; Geoapify calculates the road line after an
assigned rider starts navigation. Missing pins or a missing routing key keep
the line unavailable, with address-based external directions as a fallback.

## Configuration

1. Provide a Geoapify Routing API client key for the web build as
   `VITE_GEOAPIFY_API_KEY`. Vite embeds this value into the browser bundle, so
   it is public. Restrict the web key to the intended origins and review the
   provider's usage limits and costs.
2. Provide a separate public Rider mobile build value named
   `GEOAPIFY_API_KEY`. The value can be supplied through an ignored local
   `--dart-define-from-file` configuration; do not put an actual key in source
   control or shell history. Mobile build values are extractable. The provider
   states that browser-origin restrictions do not protect native app keys, so
   monitor and rotate the mobile key. An authenticated backend routing service
   would be needed for stronger server-side key control.
3. Apply the reviewed database migration in the intended environment as part of
   the normal deployment process. Existing sellers and hubs must save their own
   pins; earlier parcels can legitimately lack saved coordinates.
4. `CHECKOUT_REQUIRE_DOORSTEP_PIN` defaults to `false` while buyer mobile is
   being updated. Enable it only after every supported checkout can choose a
   pin, save it for future use and handle validation feedback. When enabled,
   new doorstep orders without coordinates or a saved address are rejected;
   select an existing address or save the new one. If the address book is
   full, choose an existing address or remove one before saving a new one.
   Hub self-pickup and exact retries of already placed orders remain supported.
   Web checkout already requires and saves doorstep pins even while the shared
   server enforcement remains off for compatibility with older clients. The
   checkout response exposes `doorstepPinRequired` to indicate the server rule.

## Map tile availability

The Buyer and Courier web maps and Rider's embedded Leaflet map use the
standard OpenStreetMap raster tile service. Normal interactive viewing is
supported when its [tile usage policy](https://operations.osmfoundation.org/policies/tiles/)
is followed, but availability is best-effort with no service-level guarantee.
Keep attribution visible, retain normal browser Referer and cache behavior, and
identify native app requests with the app's stable User-Agent. Do not prefetch
tiles or offer offline downloads from this service. Verify the network before a
live demonstration and keep the current retry/address fallback visible; choose
a hosted OSM-derived provider or self-hosted tiles if the deployment needs a
service guarantee.

## Buyer client contract

The checkout page's saved addresses include an optional `location_token`.
Clients that select an existing address can submit its ID as
`saved_address_id`, the token as `saved_address_location_token`, and the
chosen coordinates as `shipping_latitude` and `shipping_longitude`. A changed
address or pin returns a `saved_address_id` validation error; reload and review
the current address before trying again. Older clients may omit both ID and
token, but do not receive saved-pin update and stale-selection protection.

For buyer mobile integration, a new doorstep address must submit both
coordinates and `save_address: true`. For an existing address, submit its ID,
its `location_token`, its current physical address fields and the confirmed
coordinates; use `save_address: false` to update that address instead of
creating a duplicate. On a stale-token error, reload the address and ask the
buyer to review the pin. If the address book is full, select an existing
address or remove one before saving a new one. Hub pickup is exempt from the
new doorstep requirements. Keep the same checkout token and payload for an
exact retry, so a successful order is not submitted twice.

The currently inspected buyer mobile checkout copies saved coordinates into
its form but does not submit the saved address ID or location token, and allows
`save_address` to be false. Its address editor resends coordinates without
`pin_confirmed`, so an edit to physical fields clears the old pin. These
observations are not a release gate by themselves:
recheck the other team's latest work and verify registration, address editing,
new and existing checkout, a full address book, stale pins, and retry behavior
before enabling the shared requirement.

When an address-book edit changes the physical address, coordinates are cleared
unless the buyer places or confirms the pin and the client submits
`pin_confirmed: true`. Clients should clear an old selection on address edits
and ignore any location response that arrives after the address or pin changes.

See [Geoapify Routing API](https://apidocs.geoapify.com/docs/routing/) and its
[mobile key guidance](https://apidocs.geoapify.com/how-to/maps/react-native-maplibre-map)
for current provider behavior. Map tile requests also disclose the displayed
area to OpenStreetMap; route requests disclose the route origin and stop to
Geoapify. The rider screens explain this before navigation starts.

## Acceptance

PC tests use synthetic routes and in-memory databases. They do not verify a
provider account, a deployed API or an Android device. After configuration and
deployment, verify an approved assigned stop, a precise rider location, a real
road line for the correct vehicle, changing stops, Stop, and provider failure.
Check seller pickup, hub, and buyer stops with real saved pins. Existing orders
retain their own delivery snapshots. Physical phone permission and lifecycle
testing remains a separate step, deferred at the user's request.
