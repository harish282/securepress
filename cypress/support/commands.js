/** @typedef {'presssentinel' | 'presssentinel-health' | 'presssentinel-authentication' | 'presssentinel-security-headers' | 'presssentinel-rate-limit' | 'presssentinel-url-disguise' | 'presssentinel-file-integrity' | 'presssentinel-audit-logs' | 'presssentinel-audit-settings' | 'presssentinel-license' | 'presssentinel-woocommerce'} PressSentinelPage */

/**
 * WordPress admin login (cached session per user).
 */
Cypress.Commands.add('wpLogin', () => {
  const user = Cypress.env('wpUsername')
  const pass = Cypress.env('wpPassword')

  cy.session(
    ['wp-admin', user],
    () => {
      cy.visit('/wp-login.php')
      cy.get('#user_login').clear().type(user)
      cy.get('#user_pass').clear().type(pass, { log: false })
      cy.get('#wp-submit').click()
      cy.location('pathname', { timeout: 20000 }).should('not.include', 'wp-login.php')
    },
    { cacheAcrossSpecs: true },
  )
})

/**
 * @param {PressSentinelPage} page
 */
Cypress.Commands.add('visitPressSentinel', (page = 'presssentinel', options = {}) => {
  cy.visit(`/wp-admin/admin.php?page=${page}`, options)
})

/** WordPress Settings API checkboxes are preceded by a hidden `value="0"` input. */
Cypress.Commands.add('checkWpSetting', (fieldName) => {
  cy.get(`input[type="checkbox"][name="${fieldName}"]`).check({ force: true })
})

Cypress.Commands.add('uncheckWpSetting', (fieldName) => {
  cy.get(`input[type="checkbox"][name="${fieldName}"]`).uncheck({ force: true })
})

/**
 * Toggle a dashboard feature checkbox and save.
 * @param {string} featureKey e.g. security_headers, audit_log
 * @param {boolean} enable
 */
Cypress.Commands.add('setDashboardFeature', (featureKey, enable) => {
  cy.visitPressSentinel('presssentinel')
  cy.get(`#presssentinel_feature_${featureKey}`).then(($el) => {
    const checked = $el.prop('checked')
    if (checked !== enable) {
      cy.wrap($el).click({ force: true })
    }
  })
  cy.contains('button', 'Save feature toggles').click()
  cy.get('.notice-success', { timeout: 15000 }).should('be.visible')
})

/**
 * Save a WordPress Settings API form (options.php).
 */
Cypress.Commands.add('saveWpOptionsForm', () => {
  cy.get('#submit').click()
  // WP 6.x shows a classic notice; WP 7+ often only sets settings-updated in the redirect URL.
  cy.location('search', { timeout: 15000 }).should((search) => {
    expect(search).to.match(/settings-updated=true/)
  })
})

/**
 * Fire sequential REST requests; yields status code array.
 * @param {string} path
 * @param {number} count
 */
Cypress.Commands.add('burstRest', (path, count) => {
  const codes = []
  const next = (remaining) => {
    if (remaining <= 0) {
      return cy.wrap(codes)
    }
    return cy.request({ url: path, failOnStatusCode: false }).then((res) => {
      codes.push(res.status)
      return next(remaining - 1)
    })
  }
  return next(count)
})
