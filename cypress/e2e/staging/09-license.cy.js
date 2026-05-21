/**
 * STAGING_TEST_PLAN.md — §12 Licensing
 */
describe('License', () => {
  beforeEach(() => {
    cy.wpLogin()
  })

  it('loads license page and shows status', () => {
    cy.visitPressSentinel('presssentinel-license')
    cy.contains('h1', 'License', { matchCase: false }).should('be.visible')
    cy.get('body').should('not.contain', 'Fatal error')
    cy.get('body').then(($body) => {
      const text = $body.text()
      expect(text).to.match(/trial|Pro|license|evaluation|Active|Not configured/i)
    })
  })
})
