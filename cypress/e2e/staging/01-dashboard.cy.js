/**
 * STAGING_TEST_PLAN.md — §3 Dashboard & feature toggles
 */
describe('Dashboard & feature toggles', () => {
  const feature = 'security_headers'

  beforeEach(() => {
    cy.wpLogin()
    cy.visitNiyiGuard('niyiguard')
  })

  it('shows status overview cards', () => {
    cy.contains('h2', 'Status overview').should('be.visible')
    cy.contains('Authentication').should('be.visible')
    cy.contains('Security Headers').should('be.visible')
    cy.contains('File Integrity').should('be.visible')
    cy.contains('Audit Log').should('be.visible')
  })

  it('persists a feature toggle after save and reload', () => {
    cy.get(`#niyiguard_feature_${feature}`).then(($cb) => {
      const wasOn = $cb.prop('checked')
      const target = !wasOn

      cy.setDashboardFeature(feature, target)
      cy.reload()
      cy.get(`#niyiguard_feature_${feature}`).should(target ? 'be.checked' : 'not.be.checked')

      cy.setDashboardFeature(feature, wasOn)
    })
  })
})
