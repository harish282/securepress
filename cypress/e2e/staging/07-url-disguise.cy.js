/**
 * STAGING_TEST_PLAN.md — §9 URL disguise (skipped unless runDestructive)
 */
describe('URL disguise', () => {
  const slug = Cypress.env('urlDisguiseSlug') || 'secure-login-staging-cy'

  before(function () {
    if (!Cypress.env('runDestructive')) {
      this.skip()
    }
  })

  beforeEach(() => {
    cy.wpLogin()
  })

  after(() => {
    cy.wpLogin()
    cy.setDashboardFeature('url_disguise', false)
    cy.visitPressSentinel('presssentinel-url-disguise')
    cy.get('input[name="presssentinel_url_disguise[enabled]"]').uncheck({ force: true })
    cy.saveWpOptionsForm()
  })

  it('serves login at custom slug and blocks default when configured', () => {
    cy.setDashboardFeature('url_disguise', true)
    cy.visitPressSentinel('presssentinel-url-disguise')
    cy.get('input[name="presssentinel_url_disguise[enabled]"]').check({ force: true })
    cy.get('input[name="presssentinel_url_disguise[login_slug]"]').clear().type(slug)
    cy.get('input[name="presssentinel_url_disguise[block_default_wp_login]"]').check({
      force: true,
    })
    cy.saveWpOptionsForm()

    cy.visit('/wp-admin/options-permalink.php')
    cy.get('#submit').click()

    cy.request({ url: `/${slug}/`, failOnStatusCode: false }).then((res) => {
      expect(res.status).to.be.oneOf([200, 302])
      expect(res.body).to.match(/login|password|user_login/i)
    })

    cy.request({ url: '/wp-login.php', failOnStatusCode: false }).then((res) => {
      expect(res.status).to.be.oneOf([404, 403, 302])
    })
  })
})
