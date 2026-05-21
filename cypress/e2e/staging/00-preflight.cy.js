/**
 * STAGING_TEST_PLAN.md — §2 Pre-flight
 */
describe('Pre-flight', () => {
  beforeEach(() => {
    cy.wpLogin()
  })

  it('loads Press Sentinel dashboard without fatal errors', () => {
    cy.visitPressSentinel('presssentinel')
    cy.contains('h1', 'Press Sentinel').should('be.visible')
    cy.contains('Feature toggles').should('be.visible')
    cy.get('body').should('not.contain', 'Fatal error')
  })

  it('shows health diagnostics with storage tables', () => {
    cy.visitPressSentinel('presssentinel-health')
    cy.contains('h1', 'Health diagnostics').should('be.visible')
    cy.contains('table', 'presssentinel_audit_logs').should('exist')
    cy.contains('Active protections').should('be.visible')
  })

  it('lists all feature toggles on the dashboard', () => {
    cy.visitPressSentinel('presssentinel')
    ;[
      'auth_hardening',
      'security_headers',
      'rate_limit',
      'url_disguise',
      'file_integrity',
      'audit_log',
    ].forEach((key) => {
      cy.get(`#presssentinel_feature_${key}`).should('exist')
    })
  })
})
