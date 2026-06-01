/**
 * Dashboard review & donation section (free edition).
 */
describe('Dashboard support', () => {
  beforeEach(() => {
    cy.wpLogin()
  })

  it('shows review and donation section on dashboard', () => {
    cy.visitNiyiGuard('niyiguard')
    cy.contains('h2', 'Enjoying NiyiGuard?').should('be.visible')
    cy.contains('h3', 'Leave a review').should('be.visible')
    cy.contains('h3', 'Support development').should('be.visible')
    cy.contains('a', 'Support on Ko-fi').should('have.attr', 'href').and('include', 'ko-fi.com')
    cy.get('body').should('not.contain', 'Fatal error')
  })
})
