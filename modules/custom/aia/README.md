# Algorithmic Impact Assessment

Native Drupal 11 implementation of the Government of Canada Algorithmic Impact
Assessment v1.0.1.

## Open Government installation

Open Government uses two repositories:

```text
open-data/og       Drupal installation profile and shared custom modules
open-data/opengov  Site project, deployed configuration, and Composer lock
```

The established location for shared custom modules is
`open-data/og/modules/custom/aia`. Add `aia` to the custom-module section of
`og.info.yml`, and add `dompdf/dompdf:^3.1` to `open-data/og/composer.json`.
Then update the profile dependency and lock file from the `open-data/opengov`
project.

If maintainers explicitly want AIA to be site-specific instead, Drupal also
supports `open-data/opengov/html/modules/custom/aia`. This is valid, but it
creates a new custom-module location in that repository. In that case add the
Dompdf dependency directly to `open-data/opengov/composer.json`.

Enable the module and export the site configuration:

```sh
drush en aia -y
drush cr
drush cex -y
```

The resulting `open-data/opengov/config/core.extension.yml` must contain
`aia: 0`. Commit the applicable module/profile changes together with the
updated project `composer.lock` and exported configuration.

## Routes

- `/aia-eia-js` — assessment
- `/aia-eia-js/results` — completed results
- `/aia-eia-js/export` — JSON progress export
- `/aia-eia-js/results/pdf/en` — English PDF
- `/aia-eia-js/results/pdf/fr` — French PDF

Language switching follows Drupal's configured URL language negotiation.
With Open Government's current path-prefix configuration, the public URLs are
`/en/aia-eia-js` and `/fr/aia-eia-js`.
At deployment, preserve existing external links with edge or web-server
redirects from `/aia-eia-js?lang=en|fr` to the corresponding language-prefixed
URL.

The module does not add a main-menu link. Add one through Open Government
site configuration if the assessment should appear in navigation.

Enabling the module imports `translations/aia.fr.po` into Locale for Drupal
`t()` chrome. Questionnaire copy comes from `data/en.json`, `data/fr.json`,
and `data/survey-enfr.json` and does not depend on that import.

## Data handling

Answers are held in Drupal private temporary session storage and mirrored to
browser local storage for recovery. They are not saved as content entities or
permanent submissions. Because this is a native server-rendered Drupal form,
answers are transmitted to Drupal during navigation and a network connection
is required.

## Testing

Scoring, conditions, questionnaire structure, and UI JSON keys can be checked
without Drupal:

```sh
php tests/run.php
```

After `composer install` in a Drupal 11 project that contains this module:

```sh
vendor/bin/phpunit -c web/modules/custom/aia/phpunit.xml
vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,module,yml web/modules/custom/aia
```

From Open Government (`html/` web root):

```sh
vendor/bin/phpunit -c html/modules/custom/aia/phpunit.xml
vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,module,yml html/modules/custom/aia
```

Questionnaire content and requirements data originate from
`canada-ca/aia-eia-js` under the MIT License. See `LICENSE.upstream`.
