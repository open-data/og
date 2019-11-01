# Installation Profile for the Open Government project

[![Build Status](https://travis-ci.org/open-data/og.svg?branch=master)](https://travis-ci.org/open-data/og)


## Usage

1. Install [composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx).

Optional - [Global composer installation](https://getcomposer.org/doc/00-intro.md#globally).
If skipping, you may need to replace `composer` with `php composer.phar` for your setup.

2. Create project

```
composer create-project opengov/opengov-project:dev-master MYPROJECT --no-interaction
```

3. Install using interactive installer, choose `Open Government` as your installation profile. 


## Scope
The installation profile installs Drupal Core, contributed and custom modules and theme.

### Contributed Modules

The following contributed modules are installed as part of the profile
	- admin_toolbar
	- autologout
	- bootstrap_layouts
	- ckeditor_codemirror
	- csv_serialization
	- ctools
	- facets
	- fontawesome
	- google_analytics
	- honeypot
	- memcache
	- menu_breadcrumb
	- metatag
	- pathauto
	- redirect
	- search_api
	- search_api_solr
	- simple_sitemap
	- token
	- token_filter
	- views_bootstrap
	- webform

### Theme

The theme [GCWeb](https://github.com/open-data/gcweb_bootstrap) is installed and enabled by the profile.