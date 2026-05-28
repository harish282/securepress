const { defineConfig } = require('cypress')

module.exports = defineConfig({
  e2e: {
    baseUrl: process.env.CYPRESS_BASE_URL || 'http://localhost',
    specPattern: 'cypress/e2e/**/*.cy.js',
    supportFile: 'cypress/support/e2e.js',
    defaultCommandTimeout: 15000,
    requestTimeout: 20000,
    video: false,
    screenshotOnRunFailure: true,
    retries: {
      runMode: 1,
      openMode: 0,
    },
    setupNodeEvents(on, config) {
      const baseUrl = process.env.CYPRESS_BASE_URL || config.env.baseUrl
      if (baseUrl) {
        config.baseUrl = baseUrl
        config.env.baseUrl = baseUrl
      }
      return config
    },
  },
  env: {
    wpUsername: 'admin',
    wpPassword: 'password',
    /** Set true to run lockout, URL disguise, and plugin-toggle tests. */
    runDestructive: false,
    /** Set true when WooCommerce + Pro are active on staging. */
    runWooCommerce: false,
    /** Set true only when a real NiyiGuard license is installed. */
    hasLicense: false,
    /** Custom login slug when testing URL disguise (section 9). */
    urlDisguiseSlug: 'secure-login-staging-cy',
  },
})
