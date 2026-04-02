# Washington University Arts and Science Drupal 10 Migration

This is the developer documentation for the Washington University Arts and Science Drupal 10 Migration.

## TABLE OF CONTENTS

1. [Local Environment Overview](#local-environment-overview)
   1. [DDEV and Colima](#i-ddev-and-colima)
   2. [DDEV and Docker Desktop](#ii-ddev-and-docker-desktop)
   3. [Lando and Docker Desktop](#iii-lando-and-docker-desktop)
2. [Common Commands](#common-commands)
   1. [NVM](#i-nvm)
   2. [Yarn](#ii-yarn)
3. [Config Splits](#3-configuration-splits)
   1. [Config Split Rule #1](#i-the-one-rule-you-should-never-break)
   2. [Site Split](#ii-site-split)
   3. [Config Ignore](#iii-config-ignore)
   4. [Best Practices](#iv-best-practices)
      1. [Complete Split List](#complete-split-list)
      2. [Partial Split List](#partial-split-list)
      3. [How to Split a Custom Content Type](#split-best-practices-custom-content)
4. [Locked Packages](#4-locked-packages)

___

# Local Environment Overview
___


Drupal 10 is installed in the root folder of the project.

Clone the repository to the local computer:

```bash
git clone git@gitlab.com:spry-digital/washington-university/wash-u-a-and-s-multisite.git
```

Make sure you can clone down the repo; otherwise, ask Dani to add your SSH key to the repository.

For your local environment, there are three paths you can follow:

1. [DDEV and Colima (Dani's recommendation)](#colima)
2. [DDEV and Docker Desktop](#dockerd)
3. [Lando and Docker Desktop](#lando)

<a id="colima"></a>

> ##  I. DDEV AND COLIMA

1. Add a config.yaml file to .ddev directory using the [following snippet](https://wustl.box.com/shared/static/3oltw37nxef861c8nbe05tbfw2kuftc4.yaml).
2. Add a settings.php file in `wash-u-a-and-s-multisite/web/sites/default` using the [following snippet](https://wustl.box.com/s/snec2l80dfwjbwi866yvz3y4d4u753tu).
3. Install Homebrew if not already installed: [Install homebrew](https://brew.sh/), then run the following command:
    ```bash
    brew install npm yarn php@8.1 git composer node@16 colima docker mkcert drud/ddev/ddev
    ```
4. To bypass any HTTPS errors, run:

      ```bash
      mkcert -install
      ```
   1. `NOTE:`It will prompt you to install the package if you don't have it.
   
5. To start Colima, use the following command:

   ```bash
   colima start --cpu 4 --memory 6 --disk 100 --vm-type=qemu --mount-type=sshfs --dns=1.1.1.1
   ```

    1. `NOTE:` You will need to run `colima start` then `ddev start` every time you reboot your laptop.
6. Authenticate SSH:

   ```bash
   ddev auth ssh
   ```
7. Start DDEV:

   ```bash
   ddev start
   ```

    1. `NOTE:` This should install composer packages, but just to be safe, you can run `ddev composer install`.

    2. `NOTE:` This will fail without a database synced down locally. Do not panic.
8. Sync down the migration environment using:

   ```bash
   ddev qa-sync
   ```

    1. `NOTE:` We recommend running `ddev qa-sync` as it has the most test content.
9. To login to your local, use:

   ```bash
   ddev drush uli
   ```

   The site will be available locally at [https://olympian10.ddev.site:3000/](https://olympian10.ddev.site:3000/).


<a id="dockerd"></a>

>
> ##  II. DDEV AND DOCKER DESKTOP
>

1. Add a config.yaml file to .ddev directory using the [following snippet](https://wustl.box.com/shared/static/3oltw37nxef861c8nbe05tbfw2kuftc4.yaml).
2. Add a settings.php file in `wash-u-a-and-s-multisite/web/sites/default` using the [following snippet](https://wustl.box.com/s/snec2l80dfwjbwi866yvz3y4d4u753tu).

3. Install Homebrew if not already installed: [Install homebrew](https://brew.sh/), then run the following command:

```bash
brew install npm yarn php@8.1 git composer node@16
```

4. Download Docker: [Docker Desktop](https://ddev.readthedocs.io/en/stable/users/install/docker-installation/#macos)

    1. `NOTE:` Make sure it's compatible with your OS.

   1. `NOTE:` You will need to start Docker Desktop before you're able to run `ddev start`. You will need to do this every time you reboot your laptop.
5. Authenticate SSH:

   ```bash
   ddev auth ssh
   ```
6. Start DDEV:

   ```bash
   ddev start
   ```

    1. `NOTE:`This should install composer packages, but just to be safe, you can run `ddev composer install`.
    2. `NOTE:` This will fail without a database synced down locally. Do not panic.
7. Sync down the migration environment using:

   ```bash
   ddev qa-sync
   ```

    1. `NOTE:` We recommend running `ddev qa-sync` as it has the most test content.
8. To login to your local, use:

   ```bash
   ddev drush uli
   ```

   The site will be available locally at [https://olympian10.ddev.site:

3000/](https://olympian10.ddev.site:3000/).

<a id="lando"></a>

> ##  III. LANDO AND DOCKER DESKTOP


1. Add a settings.php file in `wash-u-a-and-s-multisite/web/sites/default` using the [following snippet](https://wustl.box.com/s/snec2l80dfwjbwi866yvz3y4d4u753tu).
2. Install Homebrew if not already installed: [Install homebrew](https://brew.sh/), then run the following command:

   ```bash
   brew install npm yarn php@8.1 git composer node@16 lando
   ```
3. Download Docker: [Docker](https://www.docker.com/products/docker-desktop/)

    1. `NOTE:` Make sure it's compatible with your OS.
    2. `NOTE:` You will need to start Docker Desktop before you're able to run `lando start`. You will need to do this every time you reboot your laptop.
4. Start Lando:

   ```bash
   lando start
   ```
5. Sync down the migration environment using:

   ```bash
   lando qa-sync
   ```

    1. NOTE: We recommend running `lando qa-sync` as it has the most test content.
6. To run composer to install and update dependencies, use:

   ```bash
   lando composer install
   ```
7. To login to the backend, use:

   ```bash
   lando drush uli
   ```

   The site will be available locally at [https://olympian10.ddev.site:3000/](https://olympian10.ddev.site:3000/).

<a id="commands"></a>

---

# COMMON COMMANDS
---

Modules and other Drupal dependencies are managed with **Composer** Packages and Drupal theme dependencies will be managed with **Yarn**.
The Drupal theme directory and assets are found in the `/web/themes/custom/olympian9` folder.

<a id="nvm"></a>


> ## I. NVM

NVM is a version manager for Node.js

To install or update NVM, run the install script either by [downloading and running the script manually](https://github.com/nvm-sh/nvm/blob/v0.39.3/install.sh) or using the following cURL or Wget command:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.3/install.sh | bash
```

or

```bash
wget -qO- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.3/install.sh | bash
```

Now you can install and target a specific node version using:

```bash
nvm install 18
nvm use 18
```

</details>

<a id="yarn"></a>

> ## II. Yarn

It is recommended to install Yarn [^1] through the npm package manager, which comes bundled with Node.js when you install it on your system with NVM.

#### I am working on a new project. [^2]

```bash
npm install --global yarn
```

Now you can replace npm with yarn for package management. For example:

```bash
yarn install
yarn run build
```

Here's a [link comparing npm vs yarn commands](https://classic.yarnpkg.com/lang/en/docs/migrating-from-npm/) if you ever need to install a package.

Note: Every template/SCSS change requires a `ddev drush cr` or `lando drush cr` to see your changes locally.


<a id="config-splits"></a>

---

# 3. CONFIGURATION SPLITS
---


There are a few prerequisites that you should read and understand before working with config splits. For more details, visit [Drupal.org - Configuration Split Issue](https://www.drupal.org/project/config_split/issues/2885643#comment-12125863).

<a id="split-rule"></a>

> ## I. The one rule you should never break

**DO NOT** share theme-dependent configuration in the sync directory if your site split has complete-split a custom theme. The shared configuration will be deleted on export, which will break when merging in new theme configuration. An example of this would be a paragraph or media change.

<a id="split-site"></a>

> ## II. Site Split

The machine name of the split should be `site`, and the directory should be `../config/sites/mysite.wustl.edu`, replacing `mysite.wustl.edu` with the multisite URL host.

To register a configuration split for a multisite, create the split locally in the UI and export to the site split directory:

```bash
ddev drush config-split:export site
```

Deploy the code changes to each environment per the normal process and import the configuration from the split manually:

```bash
ddev drush @mysite.dev config:import --source ../config/sites/mysite.wustl.edu

 --partial
```


<a id="split-ignore"></a>

> ## III. Config Ignore


This split should be used for configuration that site staff, editors, etc. can change in production. Think of it as a config split with database storage. The high weight means config entities in this split will take precedence on import.

Configuration that is ignored cannot be selectively enabled/disabled in environment splits. Use Drupal's [configuration override system](https://www.drupal.org/docs/8/api/configuration-api/configuration-override-system) if you need to override configuration per environment.

<a id="split-best-practices"></a>

---

# IV. Best Practices

---
<a id="split-best-practices"></a>

> ## Complete vs. Partial Split?

You will need to decide whether to add your config items to the Complete list or Partial list sections. Following these practices makes it easier for another developer to see at a glance which configuration is new and which is overriding existing configuration.

<a id="split-best-practices-complete"></a>

> ### Complete Split List

Any configuration that is completely unique and not duplicated in the sync configuration or another split. This would include custom content types, custom vocabularies, and custom fields added to existing content types.

You can complete-split individual modules that your site needs, and Config Split will enable them.

<a id="split-best-practices-partial"></a>

> ### Partial Split List

Configuration that is overriding existing settings, content types, etc. This would include `user.role.*.yml`, re-ordering of fields in the entity display, or the entity form.

<a id="split-best-practices-custom-content"></a>

> ## How to Split a Custom Content Type

A custom content type consists of several types of interrelated configuration: `node.type.*.yml`, `field.storage.*.*.yml`, `field.field.*.*.*.yml`, `core.entity_form_display.*.*.yml`, and `core.entity_view_display.*.*.yml` at a minimum. The rules of configuration dependencies mean that if you add some of these items, the others will be inferred from that. After you set up your content type, it is a good idea to run `ddev drush cst` to see a list of the config items that are new or have changed.

- Add the `node.type.*.yml` to the config split first. After that, run `ddev drush config-split:export site`. You will notice that many config files get exported that were not added to the split.
- Run `ddev drush cst` again to see what additional config elements need to be added.

<a id="locked"></a>

---
# 4. LOCKED PACKAGES
---



| Package                          | Reason                                                                                                                                            |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| node_revision_delete 8.x-1.0-rc3 | No stable release to compare with D9 that works.                                                                                                  |
| drupal/photoswipe 3.1.0          | Took away grouping images. I had to create the initial patch a year and a half ago and am desperately waiting for the next person to do the same. |
| admin_toolbar 3.3.0              | The updates break the toolbar styling. Leaving it here until it's fixed.                                                                          |

