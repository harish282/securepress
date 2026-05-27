/**
 * STAGING_TEST_PLAN.md — §4 Audit log
 */
describe('Audit log', () => {
  before(() => {
    cy.wpLogin()
    cy.setDashboardFeature('audit_log', true)
  })

  beforeEach(() => {
    cy.wpLogin()
  })

  it('opens audit log viewer and filters', () => {
    cy.visitPressSentinel('presssentinel-audit-logs')
    cy.contains('h1', 'PressSentinel Audit Logs').should('be.visible')
    cy.get('select[name="category"]').should('exist')
    cy.get('select[name="level"]').should('exist')
    cy.get('input[name="s"]').should('exist')
    cy.contains('button', 'Filter').click()
    cy.get('.wp-list-table').should('exist')
  })

  it('saves audit settings retention', () => {
    cy.visitPressSentinel('presssentinel-audit-settings')
    cy.contains('h1', 'PressSentinel audit log settings').should('be.visible')
    cy.get('input[name="presssentinel_audit_log[retention_days]"]')
      .clear()
      .type('90')
    cy.saveWpOptionsForm()
    cy.get('input[name="presssentinel_audit_log[retention_days]"]').should('have.value', '90')
  })

  it('runs prune now from maintenance section', () => {
    cy.visitPressSentinel('presssentinel-audit-logs')
    cy.window().then((win) => {
      cy.stub(win, 'confirm').returns(true)
    })
    cy.contains('button', 'Run prune now').click()
    cy.get('.notice-success', { timeout: 20000 }).should('be.visible')
  })

  it('shows event detail when detail link exists', () => {
    cy.visitPressSentinel('presssentinel-audit-logs')
    cy.get('tbody tr').then(($rows) => {
      if ($rows.find('a:contains("View")').length === 0) {
        cy.log('No audit events yet — skip detail view (run plugin toggle with runDestructive)')
        return
      }
      cy.contains('tbody a', 'View').first().click()
      cy.contains('h2', 'Event detail').should('be.visible')
    })
  })
})
