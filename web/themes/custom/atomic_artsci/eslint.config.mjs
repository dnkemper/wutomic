/*
Created using yarn create @eslint/config

Log:
----------
@eslint/create-config: v1.11.0

✔ What do you want to lint? · javascript
✔ How would you like to use ESLint? · problems
✔ What type of modules does your project use? · esm
✔ Which framework does your project use? · none
✔ Does your project use TypeScript? · No / Yes
✔ Where does your code run? · browser
ℹ The config that you've selected requires the following dependencies:

eslint, @eslint/js, globals
✔ Would you like to install them now? · No / Yes
✔ Which package manager do you want to use? · yarn
----------

And then added an "ignores" section to skip 3rd-party files.
*/
import js from "@eslint/js";
import globals from "globals";
import { defineConfig } from "eslint/config";

export default defineConfig([
  { files: ["**/*.{js,mjs,cjs}"],
    plugins: { js },
    extends: [
      "js/recommended",
    ],
    languageOptions: {
      globals: {
        ...globals.browser, // Adds 'window'
        "_": true,
        "AccessibleMenu": true,
        "Backbone": true,
        "bodyScrollLock" : true,
        "CKEditor5": true,
        "Cookies": true,
        "DocumentTouch": true,
        "Drupal": true,
        "drupalSettings": true,
        "drupalTranslations": true,
        "FloatingUIDOM": true,
        "htmx": true,
        "jQuery": true,
        "loadjs": true,
        "Modernizr": true,
        "once": true,
        "ScrollReveal": true,
        "Shepherd": true,
        "Sortable": true,
        "tabbable": true,
        "transliterate": true,
      }
    }
  },
  { ignores: [
    "js/**/*.min.js",  // 3rd-party
    "js/libs/*", // 3rd-party
    "node_modules/*",
    "**/node_modules/*",
  ]},
	{ rules: {
    // Most of the following were copied from core/.eslintrc.json.
    "consistent-return": ["off"],
    "no-underscore-dangle": ["off"],
    "max-nested-callbacks": ["warn", 4], // orig 3; add 1 to allow for once()
    "no-plusplus": ["warn", {
      "allowForLoopAfterthoughts": true
    }],
    "no-param-reassign": ["off"],
    "no-prototype-builtins": ["off"],
    // "operator-linebreak": ["error", "after", { "overrides": { "?": "ignore", ":": "ignore" } }],
    "no-unused-vars": [
      "warn", // just warn instead of error
      { "args": "none" }  // ignore when unused variables are function arguments
    ],
    "no-undef": "warn",
  }},
]);
