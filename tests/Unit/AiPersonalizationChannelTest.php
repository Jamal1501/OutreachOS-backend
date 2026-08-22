<?php

namespace Tests\Unit;

use App\Services\AiGatewayService;
use App\Services\AiPersonalizationService;
use Tests\TestCase;

class AiPersonalizationChannelTest extends TestCase
{
    public function test_email_generation_keeps_the_selected_type_and_always_returns_a_subject(): void
    {
        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->once())
            ->method('structured')
            ->willReturn($this->messageResult([
                'messageType' => 'email',
                'emailSubject' => '',
                'personalizedMessage' => $this->emailBody(),
            ]));

        $result = (new AiPersonalizationService($gateway))->personalize([
            'creator' => ['handle' => '@creator', 'platform' => 'instagram'],
            'messageType' => 'email',
            'taskContext' => ['emailSubject' => 'A creator idea for SocialCore'],
        ]);

        $this->assertSame('email', $result['messageType']);
        $this->assertSame('A creator idea for SocialCore', $result['emailSubject']);
        $this->assertStringNotContainsString('Subject:', $result['personalizedMessage']);
    }

    public function test_mismatched_ai_channel_is_repaired_and_cannot_override_the_selected_dm(): void
    {
        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->exactly(2))
            ->method('structured')
            ->willReturnOnConsecutiveCalls(
                $this->messageResult([
                    'messageType' => 'email',
                    'emailSubject' => 'Partnership idea',
                    'personalizedMessage' => $this->emailBody(),
                ]),
                $this->messageResult([
                    'messageType' => 'dm',
                    'emailSubject' => '',
                    'personalizedMessage' => 'Quick idea for a creator-led workflow test using the product. Open to seeing the short concept?',
                ]),
            );

        $result = (new AiPersonalizationService($gateway))->personalize([
            'creator' => ['handle' => '@creator', 'platform' => 'instagram'],
            'messageType' => 'dm',
        ]);

        $this->assertSame('dm', $result['messageType']);
        $this->assertSame('', $result['emailSubject']);
        $this->assertStringNotContainsString('Subject:', $result['personalizedMessage']);
    }

    public function test_selected_app_language_is_part_of_the_mandatory_generation_contract(): void
    {
        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->once())
            ->method('structured')
            ->with(
                $this->anything(),
                $this->callback(fn (string $prompt) => str_contains($prompt, "OUTPUT LANGUAGE - mandatory\nGerman.")),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->messageResult([
                'messageType' => 'email',
                'emailSubject' => 'Eine konkrete Content-Idee',
                'personalizedMessage' => $this->emailBody(),
            ]));

        $result = (new AiPersonalizationService($gateway))->personalize([
            'creator' => ['handle' => '@creator', 'platform' => 'instagram'],
            'messageType' => 'email',
            'outputLanguage' => 'de',
        ]);

        $this->assertSame('Eine konkrete Content-Idee', $result['emailSubject']);
    }

    public function test_structured_offer_controls_are_included_in_the_generation_contract(): void
    {
        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->once())
            ->method('structured')
            ->with(
                $this->anything(),
                $this->callback(fn (string $prompt) => str_contains($prompt, '"senderType": "agency"')
                    && str_contains($prompt, '"showClientName": false')
                    && str_contains($prompt, '"compensationMode": "range"')
                    && str_contains($prompt, '"budgetMin": 800')
                    && str_contains($prompt, '"deliverableMode": "flexible"')),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->messageResult([
                'messageType' => 'dm',
                'personalizedMessage' => 'We have a paid creator opportunity that fits your format. Open to the short details?',
            ]));

        (new AiPersonalizationService($gateway))->personalize([
            'creator' => ['handle' => '@creator', 'platform' => 'instagram'],
            'messageType' => 'dm',
            'outreachContext' => [
                'senderType' => 'agency',
                'showClientName' => false,
                'compensationMode' => 'range',
                'budgetMin' => 800,
                'budgetMax' => 1200,
                'deliverableMode' => 'flexible',
            ],
        ]);
    }

    public function test_first_outreach_requires_a_visible_reference_when_real_post_evidence_exists(): void
    {
        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->once())
            ->method('structured')
            ->with(
                $this->anything(),
                $this->callback(fn (string $prompt) => str_contains($prompt, 'A real recent post is available')
                    && str_contains($prompt, 'long-distance date ideas')
                    && str_contains($prompt, 'reference one supported post topic naturally')),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->messageResult([
                'messageType' => 'dm',
                'personalizedMessage' => 'Your post about long-distance date ideas made me think this paid collaboration could fit naturally. Open to the details?',
            ]));

        (new AiPersonalizationService($gateway))->personalize([
            'creator' => [
                'handle' => '@creator',
                'platform' => 'instagram',
                'recentPosts' => [['caption' => 'Three long-distance date ideas that actually feel personal.']],
            ],
            'messageType' => 'dm',
            'stage' => 'cold_invite',
        ]);
    }

    public function test_creator_rate_mode_removes_a_stale_fixed_budget_from_the_ai_prompt(): void
    {
        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->once())
            ->method('structured')
            ->with(
                $this->anything(),
                $this->callback(fn (string $prompt) => str_contains($prompt, '"compensationMode": "creator_rates"')
                    && ! str_contains($prompt, '"budgetFixed"')
                    && ! str_contains($prompt, '300')),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->messageResult([
                'messageType' => 'dm',
                'personalizedMessage' => 'We have a paid creator opportunity in mind. Could you share your rates for this type of partnership?',
            ]));

        (new AiPersonalizationService($gateway))->personalize([
            'creator' => ['handle' => '@creator', 'platform' => 'instagram'],
            'messageType' => 'dm',
            'outreachContext' => [
                'compensationMode' => 'creator_rates',
                'budgetFixed' => 300,
                'showBudget' => true,
            ],
        ]);
    }

    private function messageResult(array $overrides): array
    {
        return array_merge([
            'personalizedMessage' => 'A useful creator outreach draft.',
            'emailSubject' => '',
            'personalizationNotes' => 'Built from the supplied context.',
            'creativeAngle' => 'Workflow test',
            'contentIdea' => 'Show the workflow before and after using the product.',
            'fitScore' => 75,
            'confidenceScore' => 72,
            'toneUsed' => 'warm_direct',
            'messageType' => 'dm',
            'analysis' => [
                'risksToAvoid' => [],
            ],
        ], $overrides);
    }

    private function emailBody(): string
    {
        return 'Hi there, I have a creator-led content idea that could fit naturally into your usual workflow. '
            .'The concept is a short before-and-after test showing how the product changes one real step in your process. '
            .'It gives your audience something concrete to compare without forcing a scripted endorsement. '
            .'If that sounds relevant, I can send the brief, timing, and collaboration details. Best, SocialCore';
    }
}
