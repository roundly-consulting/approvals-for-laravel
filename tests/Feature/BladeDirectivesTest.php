<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

function renderBlade(string $template, array $data): string
{
    return trim(Blade::render($template, $data));
}

it('gates content with the approved directive', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $template = '@approved($deployment, $actor) YES @endapproved';

    expect(renderBlade($template, compact('deployment', 'actor')))->toBe('');

    $actor->approve($deployment);

    expect(renderBlade($template, compact('deployment', 'actor')))->toBe('YES');
});

it('gates content with the rejected directive', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $actor->reject($deployment);

    $template = '@rejected($deployment, $actor) R @endrejected';

    expect(renderBlade($template, compact('deployment', 'actor')))->toBe('R');
});

it('gates content with the pendingApproval directive', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $template = '@pendingApproval($deployment) P @endpendingApproval';

    expect(renderBlade($template, compact('deployment')))->toBe('');

    Approvals::for($deployment)->as($actor)->request();

    expect(renderBlade($template, compact('deployment')))->toBe('P');
});
