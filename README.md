=== PressSentinel ===
Contributors: harish282
Tags: security, authentication, audit, two-factor, woocommerce
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

2FA, audit log, login lockouts, security headers, file integrity, and WooCommerce protection.

== Description ==

# PressSentinel

> Laravel-inspired security infrastructure for WordPress.

PressSentinel is a modern security framework plugin for WordPress focused on middleware-based protection, developer experience, WooCommerce security, and lightweight architecture.

Unlike traditional bloated firewall plugins, PressSentinel focuses on application-layer security inspired by modern PHP frameworks like Laravel.

---

# Vision

PressSentinel aims to become:

> "The Laravel-style security framework for WordPress developers."

Core principles:

* Modern architecture
* Middleware-driven security
* Developer-first APIs
* Lightweight and modular
* WooCommerce-friendly
* Extensible and scalable

---

# Current Status

## Project Stage

* [ ] Planning
* [ ] Architecture Design
* [ ] MVP Development
* [ ] Alpha Release
* [ ] Beta Release
* [ ] Public Launch

## Agile Roadmap

* [ ] Roadmap document created: [`ROADMAP_AGILE.md`](ROADMAP_AGILE.md)
* [ ] Sprint issues created in GitHub project
* [ ] Sprint 0 initialized

## MU Loader (Early Load)

To make PressSentinel load earlier in WordPress lifecycle, install the MU loader:

* [ ] Copy `mu-loader/00-press-sentinel-loader.php` to `wp-content/mu-plugins/00-press-sentinel-loader.php`
* [ ] Keep `PressSentinel` active in normal plugin list
* [ ] Verify plugin list shows: `MU Loader: Installed`

Install guide: [`docs/MU_LOADER_INSTALL.md`](docs/MU_LOADER_INSTALL.md)

## Usage Guide

How to use what's already shipped (CSRF, rate limiter, signed URLs) inside WordPress, with end-to-end recipes for REST endpoints, admin-post forms, magic-link login, paid downloads, and WooCommerce checkout throttling: [`docs/USAGE.md`](docs/USAGE.md).

---

# Core Features

## Security Middleware System

* [ ] Middleware pipeline
* [ ] Request interception
* [ ] Route protection
* [ ] Middleware registration system
* [ ] Custom middleware support

Example:

```php
Security::middleware([
    RateLimit::class,
    CsrfProtection::class,
    BotProtection::class,
]);
```

---

## Rate Limiting

* [ ] Login rate limiting
* [ ] REST API throttling
* [ ] XML-RPC protection
* [ ] WooCommerce checkout throttling
* [ ] Contact form protection
* [ ] User/IP-based throttling
* [ ] Temporary bans

Example:

```php
RateLimiter::for('login', 5, 'minute');
```

---

## Authentication Security

* [ ] Brute-force protection
* [ ] Failed login detection
* [ ] Session management
* [ ] Suspicious login alerts
* [ ] Device tracking
* [ ] Login notifications

---

## Two-Factor Authentication

* [ ] TOTP support
* [ ] Email OTP
* [ ] Backup codes
* [ ] Trusted devices
* [ ] Recovery flow

---

## CSRF Protection

* [ ] Secure token generation
* [ ] Token expiration
* [ ] Middleware validation
* [ ] Form protection helpers
* [ ] API token support

---

## Signed URLs

* [ ] Temporary signed URLs
* [ ] Expiring links
* [ ] Download protection
* [ ] Invite links
* [ ] Password reset links

Example:

```php
Security::signedUrl('/download/123', expires: 3600);
```

---

## Security Headers

* [ ] CSP support
* [ ] HSTS support
* [ ] X-Frame-Options
* [ ] Referrer Policy
* [ ] Permissions Policy
* [ ] Header presets

---

## Audit Logging

* [ ] Login logs
* [ ] Failed login logs
* [ ] Plugin change logs
* [ ] Role change logs
* [ ] Admin activity logs
* [ ] WooCommerce activity logs
* [ ] Export functionality

---

## Developer SDK

* [ ] Security helper APIs
* [ ] Route protection helpers
* [ ] Middleware registration APIs
* [ ] Event system
* [ ] Extension support
* [ ] Validation system

Example:

```php
Security::protectRoute('/admin/export');
```

---

# WooCommerce Security

## Planned Features

* [ ] Fake checkout prevention
* [ ] Coupon abuse prevention
* [ ] Bot cart protection
* [ ] Registration spam protection
* [ ] API abuse protection
* [ ] Checkout anomaly detection

---

# Malware & Integrity Features

## Future Features

* [ ] File integrity monitoring
* [ ] Core file verification
* [ ] Suspicious PHP detection
* [ ] Malware signature scanning
* [ ] Obfuscated code detection

---

# SaaS Roadmap

## Cloud Features

* [ ] Central dashboard
* [ ] Multi-site management
* [ ] Attack analytics
* [ ] Shared threat intelligence
* [ ] Remote controls
* [ ] Security reports

---

# Technical Architecture

## Backend Stack

* [ ] PHP 8.2+
* [ ] Composer
* [ ] PSR-4 autoloading
* [ ] Dependency Injection Container
* [ ] WordPress REST API
* [ ] Event system
* [ ] Monolog integration

---

## Frontend Stack

* [ ] React admin dashboard
* [ ] Gutenberg components
* [ ] Responsive admin UI
* [ ] Settings dashboard
* [ ] Log viewer

---

# Suggested Folder Structure

```txt
presssentinel/
├── bootstrap/
├── config/
├── resources/
├── routes/
├── src/
│   ├── Core/
│   ├── Middleware/
│   ├── Security/
│   ├── Auth/
│   ├── Logging/
│   ├── Validation/
│   ├── Http/
│   ├── Admin/
│   └── WooCommerce/
├── storage/
│   ├── logs/
│   └── cache/
├── tests/
├── vendor/
├── press-sentinel.php
└── composer.json
```

---

# Development Roadmap

# Phase 1 — Foundation

## Architecture

* [ ] Setup GitHub repository
* [ ] Setup Composer
* [ ] Configure PSR-4 autoloading
* [ ] Create plugin bootstrap
* [ ] Build service container
* [ ] Create configuration system
* [ ] Setup logging
* [ ] Setup coding standards

---

# Phase 2 — Security Core

## Middleware Engine

* [ ] Request pipeline
* [ ] Middleware manager
* [ ] Middleware execution order
* [ ] Request interception
* [ ] Response handling

## Security Features

* [ ] Rate limiter
* [ ] Login protection
* [ ] CSRF middleware
* [ ] Signed URLs
* [ ] Security headers

---

# Phase 3 — Developer APIs

## SDK

* [ ] Security helper functions
* [ ] Public API documentation
* [ ] Validation system
* [ ] Event system
* [ ] Extension architecture

---

# Phase 4 — Logging & Dashboard

## Dashboard

* [ ] Security overview page
* [ ] Logs page
* [ ] Settings page
* [ ] Threat analytics
* [ ] Alerts UI

## Logging

* [ ] Audit logs
* [ ] Search logs
* [ ] Export logs
* [ ] Log retention settings

---

# Phase 5 — WooCommerce Security

## WooCommerce Module

* [ ] Checkout protection
* [ ] Registration protection
* [ ] API throttling
* [ ] Fraud detection basics

---

# Phase 6 — Testing & Launch

## Testing

* [ ] Unit tests
* [ ] Integration tests
* [ ] WordPress compatibility tests
* [ ] WooCommerce compatibility tests
* [ ] Performance testing
* [ ] Shared hosting tests

## Launch

* [ ] Documentation website
* [ ] Landing page
* [ ] GitHub releases
* [ ] Demo videos
* [ ] Beta user onboarding

---

# Monetization Plan

## Free Version

* [ ] Middleware engine
* [ ] Basic rate limiting
* [ ] Security headers
* [ ] Audit logs
* [ ] Signed URLs

---

## Pro Version

* [ ] 2FA
* [ ] WooCommerce protection
* [ ] Advanced analytics
* [ ] Threat intelligence
* [ ] Device management
* [ ] Premium support

---

# Documentation Checklist

## Developer Docs

* [ ] Installation guide
* [ ] Middleware guide
* [ ] SDK documentation
* [ ] API references
* [ ] Extension development guide
* [ ] WooCommerce integration guide

## User Docs

* [ ] Quick start guide
* [ ] Security best practices
* [ ] Troubleshooting
* [ ] FAQ

---

# Branding Checklist

* [ ] Logo design
* [ ] Brand colors
* [ ] Website domain
* [ ] Documentation branding
* [ ] Social media accounts
* [ ] GitHub organization

---

# Marketing Checklist

## Content Strategy

* [ ] Launch website
* [ ] Technical blog
* [ ] YouTube tutorials
* [ ] Dev articles
* [ ] SEO pages
* [ ] Product Hunt launch

## Community Building

* [ ] GitHub community
* [ ] Discord server
* [ ] Reddit engagement
* [ ] Facebook groups
* [ ] WordPress communities

---

# Performance Goals

* [ ] Minimal memory usage
* [ ] Low request overhead
* [ ] Shared hosting compatibility
* [ ] Fast admin dashboard
* [ ] Lazy-loaded modules

---

# Security Goals

* [ ] Secure coding standards
* [ ] OWASP best practices
* [ ] Dependency scanning
* [ ] Static analysis
* [ ] Responsible disclosure policy

---

# Future Vision

PressSentinel evolves into:

* Security framework for WordPress
* Developer infrastructure layer
* WooCommerce security platform
* SaaS security management suite
* Cloud-integrated security ecosystem

---

# License

Planned License:

* Open-source core
* Commercial premium modules

---

# Inspiration

Inspired by:

* Laravel
* Symfony
* Modern PHP architecture
* Developer-first tooling

---

# Final Goal

PressSentinel should feel like:

> "What WordPress security would look like if Laravel designed it today."
