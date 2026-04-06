<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendPlansTable();
        $this->createWorkspaceSubscriptions();
        $this->createWorkspaceCreditWallets();
        $this->createWorkspaceUsageEvents();
        $this->createCreditPackages();
        $this->createCreditPurchases();
        $this->seedPlans();
        $this->bootstrapExistingWorkspaces();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::dropIfExists('credit_packages');
        Schema::dropIfExists('workspace_usage_events');
        Schema::dropIfExists('workspace_credit_wallets');
        Schema::dropIfExists('workspace_subscriptions');
    }

    private function extendPlansTable(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'monthly_scrape_credits')) {
                $table->unsignedInteger('monthly_scrape_credits')->default(0)->after('max_creators');
            }
            if (!Schema::hasColumn('plans', 'monthly_ai_credits')) {
                $table->unsignedInteger('monthly_ai_credits')->default(0)->after('monthly_scrape_credits');
            }
            if (!Schema::hasColumn('plans', 'trial_scrape_credits')) {
                $table->unsignedInteger('trial_scrape_credits')->default(0)->after('monthly_ai_credits');
            }
            if (!Schema::hasColumn('plans', 'trial_ai_credits')) {
                $table->unsignedInteger('trial_ai_credits')->default(0)->after('trial_scrape_credits');
            }
            if (!Schema::hasColumn('plans', 'topup_price_multiplier')) {
                $table->decimal('topup_price_multiplier', 8, 2)->default(1.00)->after('trial_ai_credits');
            }
            if (!Schema::hasColumn('plans', 'stripe_price_id_monthly')) {
                $table->string('stripe_price_id_monthly')->nullable()->after('topup_price_multiplier');
            }
            if (!Schema::hasColumn('plans', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('stripe_price_id_monthly');
            }
        });
    }

    private function createWorkspaceSubscriptions(): void
    {
        if (Schema::hasTable('workspace_subscriptions')) {
            return;
        }

        Schema::create('workspace_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->unique()->index();
            $table->string('plan_id')->default('free')->index();
            $table->string('status')->default('trialing')->index();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createWorkspaceCreditWallets(): void
    {
        if (Schema::hasTable('workspace_credit_wallets')) {
            return;
        }

        Schema::create('workspace_credit_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->unique()->index();
            $table->unsignedInteger('scrape_credits_balance')->default(0);
            $table->unsignedInteger('ai_credits_balance')->default(0);
            $table->unsignedInteger('bonus_scrape_credits')->default(0);
            $table->unsignedInteger('bonus_ai_credits')->default(0);
            $table->unsignedInteger('lifetime_scrape_used')->default(0);
            $table->unsignedInteger('lifetime_ai_used')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createWorkspaceUsageEvents(): void
    {
        if (Schema::hasTable('workspace_usage_events')) {
            return;
        }

        Schema::create('workspace_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('type')->index();
            $table->string('credit_bucket')->index();
            $table->unsignedInteger('units')->default(1);
            $table->unsignedInteger('credit_cost')->default(0);
            $table->string('provider')->nullable()->index();
            $table->decimal('provider_cost_usd', 10, 4)->nullable();
            $table->string('source')->nullable()->index();
            $table->string('status')->default('reserved')->index();
            $table->string('reference_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    private function createCreditPackages(): void
    {
        if (Schema::hasTable('credit_packages')) {
            return;
        }

        Schema::create('credit_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('scrape_credits')->default(0);
            $table->unsignedInteger('ai_credits')->default(0);
            $table->decimal('price_usd', 10, 2);
            $table->json('allowed_plan_ids')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    private function createCreditPurchases(): void
    {
        if (Schema::hasTable('credit_purchases')) {
            return;
        }

        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('credit_package_id')->nullable()->index();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->unsignedInteger('scrape_credits_added')->default(0);
            $table->unsignedInteger('ai_credits_added')->default(0);
            $table->decimal('amount_paid_usd', 10, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function seedPlans(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        $now = now();
        $rows = [
            [
                'id' => 'free',
                'name' => 'Free Trial',
                'max_members' => 1,
                'max_creators' => 100,
                'monthly_scrape_credits' => 0,
                'monthly_ai_credits' => 0,
                'trial_scrape_credits' => 200,
                'trial_ai_credits' => 20,
                'topup_price_multiplier' => 1.25,
                'features' => json_encode(['trial', 'paywall_preview']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'id' => 'pro',
                'name' => 'Pro',
                'max_members' => 5,
                'max_creators' => 5000,
                'monthly_scrape_credits' => 3500,
                'monthly_ai_credits' => 250,
                'trial_scrape_credits' => 0,
                'trial_ai_credits' => 0,
                'topup_price_multiplier' => 1.00,
                'features' => json_encode(['team_workspace', 'analytics_basic']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'id' => 'enterprise',
                'name' => 'Enterprise',
                'max_members' => 25,
                'max_creators' => 25000,
                'monthly_scrape_credits' => 12000,
                'monthly_ai_credits' => 1200,
                'trial_scrape_credits' => 0,
                'trial_ai_credits' => 0,
                'topup_price_multiplier' => 0.80,
                'features' => json_encode(['team_workspace', 'analytics_advanced', 'priority_support']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('plans')->updateOrInsert(['id' => $row['id']], $row);
        }
    }

    private function bootstrapExistingWorkspaces(): void
    {
        if (!Schema::hasTable('workspaces')) {
            return;
        }

        $trialDays = (int) config('outreach.billing.trial_days', 14);
        $now = now();
        $plans = DB::table('plans')->get()->keyBy('id');

        $workspaces = DB::table('workspaces')->select('id', 'plan_id')->get();
        foreach ($workspaces as $workspace) {
            $planId = strtolower(trim((string) ($workspace->plan_id ?: 'free')));
            if ($planId === 'trial') {
                $planId = 'free';
            }
            $plan = $plans->get($planId) ?: $plans->get('free');

            if (!DB::table('workspace_subscriptions')->where('workspace_id', $workspace->id)->exists()) {
                DB::table('workspace_subscriptions')->insert([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'plan_id' => $planId,
                    'status' => $planId === 'free' ? 'trialing' : 'active',
                    'current_period_start' => $now,
                    'current_period_end' => $now->copy()->addMonth(),
                    'trial_ends_at' => $planId === 'free' ? $now->copy()->addDays($trialDays) : null,
                    'metadata' => json_encode(['bootstrapped' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (!DB::table('workspace_credit_wallets')->where('workspace_id', $workspace->id)->exists()) {
                DB::table('workspace_credit_wallets')->insert([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'scrape_credits_balance' => $planId === 'free' ? (int) ($plan->trial_scrape_credits ?? 0) : (int) ($plan->monthly_scrape_credits ?? 0),
                    'ai_credits_balance' => $planId === 'free' ? (int) ($plan->trial_ai_credits ?? 0) : (int) ($plan->monthly_ai_credits ?? 0),
                    'bonus_scrape_credits' => 0,
                    'bonus_ai_credits' => 0,
                    'lifetime_scrape_used' => 0,
                    'lifetime_ai_used' => 0,
                    'metadata' => json_encode(['bootstrapped' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
