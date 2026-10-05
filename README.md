<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/approvals-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/approvals-for-laravel/main/art/hero.png" alt="Approvals for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/approvals-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/approvals-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/approvals-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/approvals-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/approvals-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/approvals-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Approvals for Laravel

Model approvals, rejections, and multi-approver sign-off between Eloquent models. Any model can
decide on any other, and a subject can open a request that resolves by unanimous, quorum, any-one
or weighted rules — as staged pipelines, with delegation, expiry and an event for every
transition.

## Installation

Requires PHP 8.4 and Laravel 12 or 13.

```bash
composer require roundly-consulting/approvals-for-laravel
php artisan vendor:publish --tag="approvals-migrations"
php artisan migrate
```

If your models have UUID/ULID keys, set `APPROVALS_KEY_TYPE=uuid` (or `ulid`) **before** migrating.

## Usage

Give the deciding model and the decided-on model their traits:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;
use RoundlyConsulting\Approvals\Traits\HasApprovals;
use RoundlyConsulting\Approvals\Traits\RequiresApproval;

class User extends Model
{
    use GivesApprovals;
}

class Invoice extends Model
{
    use HasApprovals;
    use RequiresApproval;
}
```

Then open a request that needs two of three named approvers — it resolves as their decisions come
in:

```php
use RoundlyConsulting\Approvals\Facades\Approvals;

Approvals::request($invoice)->from([$lead, $qa, $pm])->quorum(2)->open();

Approvals::for($invoice)->as($lead)->approve();
Approvals::status($invoice);                      // ApprovalStatus::Pending — 1 of 2

Approvals::for($invoice)->as($intern)->approve(); // throws UnauthorizedApprovalException: not named

Approvals::for($invoice)->as($qa)->because('Totals check out')->approve();
Approvals::status($invoice);                      // ApprovalStatus::Approved
$invoice->isApproved();                           // true
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/approvals-for-laravel](https://roundly-consulting.com/open-source/docs/approvals-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=approvals-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
