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
