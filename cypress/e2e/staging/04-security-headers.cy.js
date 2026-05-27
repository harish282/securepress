/**
 * STAGING_TEST_PLAN.md — §6 Security headers
 */
describe('Security headers', () => {
  const master = 'presssentinel_security_headers[enabled]'
  const xfoEnabled = 'presssentinel_security_headers[x_frame_options][enabled]'
  const xfoValue = 'presssentinel_security_headers[x_frame_options][value]'

  beforeEach(() => {
    cy.wpLogin()
    cy.setDashboardFeature('security_headers', true)
  })

  after(() => {
    cy.wpLogin()
    cy.visitPressSentinel('presssentinel-security-headers')
    cy.get(`input[type="checkbox"][name="${master}"]`).then(($el) => {
      if ($el.prop('checked')) {
        cy.uncheckWpSetting(master)
        cy.saveWpOptionsForm()
      }
    })
  })

  it('enables X-Frame-Options on the front end', () => {
    cy.visitPressSentinel('presssentinel-security-headers')
    cy.checkWpSetting(master)
    cy.checkWpSetting(xfoEnabled)
    cy.get(`select[name="${xfoValue}"]`).select('SAMEORIGIN')
    cy.saveWpOptionsForm()

    cy.request({ url: '/', failOnStatusCode: false }).then((res) => {
      const h = res.headers['x-frame-options'] || res.headers['X-Frame-Options']
      expect(h, 'X-Frame-Options response header').to.match(/SAMEORIGIN/i)
    })
  })

  it('stops emitting headers when master switch is off', () => {
    cy.visitPressSentinel('presssentinel-security-headers')
    cy.uncheckWpSetting(master)
    cy.saveWpOptionsForm()

    cy.request('/').then((res) => {
      const h = res.headers['x-frame-options'] || res.headers['X-Frame-Options']
      expect(h).to.be.undefined
    })
  })
})
