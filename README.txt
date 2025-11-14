Bo City Personality (v1.0.0)
===============================

This WordPress plugin calculates a city-based persona (server-side) and returns **only the requested section as rendered HTML**, keeping the algorithm and content private.

Key features
- Server-side algorithm in PHP (no JS exposure)
- Google API Server Key read from wp-config.php as GMP_SERVER_PLACES_API_KEY
- Optional HMAC secret LOLO_HMAC_SECRET in wp-config.php (fallback to AUTH_SALT)
- Stores user inputs: country, city, lat, lng, birthday, email (optional), person name (optional), gender (optional), computed persona
- Returns a signed token; subsequent section fetches require (result_id, name, token)
- Persona content fetched on-demand; default library via CPT `city_persona`, fallback to /data/*.php

Endpoints
- GET /wp-json/bo/v1/geo?city=...&country=...&birth_date=YYYY-MM-DD&hour_slot=HH:MM&email=&person_name=&gender=&place_id=
- GET /wp-json/bo/v1/result?result_id=...&name=SUN_TIGER&section=overview&token=...

Setup
1) Define in wp-config.php:
   define('GMP_SERVER_PLACES_API_KEY', 'YOUR_SERVER_KEY');
   define('LOLO_HMAC_SECRET', 'YOUR_LONG_RANDOM_SECRET'); // optional
2) Upload the plugin folder to /wp-content/plugins/
3) Activate in WP Admin
4) Optionally, create CPT "City Persona" posts for richer content; title must match persona key (e.g., SUN_TIGER).
   - Use post content for "overview"
   - Add post meta "sections" as an array of objects: [ {key}, {title}, {content} ] per entry
5) Test with the included sample data file: data/SUN_TIGER.php

Notes
- Rate limiting is basic (per-IP via transients); adjust in includes/helpers.php
- Time Zone API is used to resolve tz id and offsets; ensure billing is enabled on Google Cloud Project.
- Replace placeholder algorithm in includes/algorithm.php with your real solar time logic for production.
Generated on 2025-10-21T22:47:27.804891Z
