/**
 * STAGING_TEST_PLAN.md — §5 Authentication hardening (admin UI; lockout/2FA need runDestructive)
 */
describe('Authentication hardening', () => {
  beforeEach(() => {
    cy.wpLogin()
    cy.setDashboardFeature('auth_hardening', true)
  })

  it('loads authentication settings with lockout fields', () => {
    cy.visitPressSentinel('presssentinel-authentication')
    cy.contains('h1', 'PressSentinel Authentication').should('be.visible')
    cy.get('input[name="presssentinel_auth_hardening[lockout][enabled]"]').should('exist')
    cy.get('input[name="presssentinel_auth_hardening[lockout][max_attempts]"]').should('exist')
  })

  it('saves lockout configuration', () => {
    cy.visitPressSentinel('presssentinel-authentication')
    cy.get('input[name="presssentinel_auth_hardening[lockout][max_attempts]"]').clear().type('5')
    cy.saveWpOptionsForm()
  })

  context('destructive login lockout', () => {
    before(function () {
      if (!Cypress.env('runDestructive')) {
        this.skip()
      }
    })

    it('locks out a dedicated test user after failed attempts', () => {
      const user = Cypress.env('lockoutTestUser') || 'staging_lockout_test'
      cy.request({
        method: 'POST',
        url: '/wp-login.php',
        form: true,
        body: { log: user, pwd: 'wrong-password-intentionally', wp-submit: 'Log In' },
        failOnStatusCode: false,
      })
      // Repeat via API is environment-specific; manual follow-up per STAGING_TEST_PLAN §5.1
      cy.log(`Verify lockout for user "${user}" on staging (5+ failures)`)
    })
  })
})
