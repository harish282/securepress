/**
 * STAGING_TEST_PLAN.md — §8 File integrity
 */
describe('File integrity', () => {
  beforeEach(() => {
    cy.wpLogin()
    cy.setDashboardFeature('file_integrity', true)
  })

  it('loads file integrity admin and offers scan', () => {
    cy.visitPressSentinel('presssentinel-file-integrity')
    cy.contains('h1', 'File integrity').should('be.visible')
    cy.contains('button', 'Run scan now').should('be.visible')
  })

  it('submits a plugins scan without fatal error', () => {
    cy.visitPressSentinel('presssentinel-file-integrity')
    cy.window().then((win) => {
      cy.stub(win, 'confirm').returns(true)
    })
    cy.contains('button', 'Run scan now').click()
    cy.get('body', { timeout: 60000 }).should('not.contain', 'Fatal error')
    cy.get('.wp-list-table, table.widefat').should('exist')
  })
})
