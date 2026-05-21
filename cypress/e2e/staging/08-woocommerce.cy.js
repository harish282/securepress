/**
 * STAGING_TEST_PLAN.md — §10 WooCommerce Protection (requires runWooCommerce)
 */
describe('WooCommerce protection', () => {
  before(function () {
    if (!Cypress.env('runWooCommerce')) {
      this.skip()
    }
  })

  beforeEach(() => {
    cy.wpLogin()
  })

  it('loads WooCommerce protection settings when Pro is active', () => {
    cy.setDashboardFeature('woocommerce_protection', true)
    cy.visitPressSentinel('presssentinel-woocommerce')
    cy.get('body').then(($body) => {
      if ($body.text().match(/upgrade|Pro license|evaluation/i)) {
        cy.log('Pro not active — enable license on staging first')
        return
      }
      cy.contains('h1', 'WooCommerce', { matchCase: false }).should('be.visible')
      cy.saveWpOptionsForm()
    })
  })
})
