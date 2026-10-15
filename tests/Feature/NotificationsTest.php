<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use NineteenNinetyFour\SignOff\Notifications\EntrySentBack;
use NineteenNinetyFour\SignOff\Notifications\EntrySignedOff;
use NineteenNinetyFour\SignOff\Notifications\ReviewNeeded;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Who's emailed, when, and what the emails say.
 */
class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Everyone exists before anything happens, so they can be asked.
        $this->author();
        $this->lawyer();
        $this->publisher();
        $this->admin();
    }

    #[Test]
    public function each_step_asks_the_people_who_can_approve_it(): void
    {
        $page = $this->draft();

        $this->signOff()->submit($this->author(), $page, 'Check the prices');

        Notification::assertSentTo($this->lawyer(), ReviewNeeded::class, function (ReviewNeeded $mail) {
            return $mail->details['step'] === 'Legal' && $mail->details['by'] === 'Author' && $mail->details['comment'] === 'Check the prices';
        });
        Notification::assertNotSentTo([$this->publisher(), $this->author(), $this->admin()], ReviewNeeded::class);

        $this->signOff()->approve($this->lawyer(), $page);

        Notification::assertSentTo($this->publisher(), ReviewNeeded::class, fn (ReviewNeeded $mail) => $mail->details['step'] === 'Publisher' && $mail->details['approvers'] === 'Lawyer (Legal)');
        Notification::assertSentToTimes($this->lawyer(), ReviewNeeded::class, 1);
    }

    #[Test]
    public function the_author_hears_when_it_is_sent_back(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'Remove the pricing claim.');

        Notification::assertSentTo($this->author(), EntrySentBack::class, function (EntrySentBack $mail) use ($page) {
            $email = $mail->toMail($this->author());

            return $email->subject === 'Sent back: About us'
                && in_array('“Remove the pricing claim.”', array_map('strval', $email->introLines), true)
                && $email->actionUrl === $page->editUrl();
        });
    }

    #[Test]
    public function the_author_hears_when_it_is_signed_off_and_nobody_is_told_twice(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->approve($this->lawyer(), $page);
        $this->signOff()->approve($this->publisher(), $page);

        Notification::assertSentTo($this->author(), EntrySignedOff::class, fn (EntrySignedOff $mail) => $mail->details['approvers'] === 'Lawyer (Legal), Publisher (Publisher)');
        Notification::assertSentToTimes($this->publisher(), ReviewNeeded::class, 1);
        Notification::assertCount(3);
    }

    #[Test]
    public function nobody_is_emailed_about_what_they_did_themselves(): void
    {
        config(['sign-off.self_approval' => true]);
        $page = $this->draft();

        // The lawyer submits, so they aren't asked to review it.
        $this->signOff()->submit($this->lawyer(), $page);
        Notification::assertNothingSentTo($this->lawyer());

        // A super user signs it off for everyone: nobody else to tell.
        $this->signOff()->approve($this->admin(), $page);
        $this->signOff()->approve($this->admin(), $page);
        Notification::assertNotSentTo($this->admin(), EntrySignedOff::class);
        Notification::assertSentTo($this->lawyer(), EntrySignedOff::class);
    }

    #[Test]
    public function super_users_are_asked_only_when_nobody_else_can_approve(): void
    {
        config(['sign-off.collections.pages.steps.0.approvers' => ['roles' => ['nobody']]]);

        $this->signOff()->submit($this->author(), $this->draft());

        Notification::assertSentTo($this->admin(), ReviewNeeded::class);
        Notification::assertNotSentTo($this->lawyer(), ReviewNeeded::class);
    }

    #[Test]
    public function emails_can_be_turned_off(): void
    {
        config(['sign-off.notifications.mail' => false]);

        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'No.');

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failed_email_does_not_stop_the_review(): void
    {
        Notification::swap(new class extends NotificationFake
        {
            public function send($notifiables, $notification)
            {
                throw new RuntimeException('Mail server down');
            }
        });

        $page = $this->draft();
        $submission = $this->signOff()->submit($this->author(), $page);

        $this->assertTrue($submission->isOpen());
    }

    #[Test]
    public function the_review_email_reads_well(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page, 'Check the prices');

        Notification::assertSentTo($this->lawyer(), ReviewNeeded::class, function (ReviewNeeded $mail) use ($page) {
            $email = $mail->toMail($this->lawyer());

            $this->assertSame('Waiting for your review: About us', $email->subject);
            $lines = array_map('strval', $email->introLines);
            $this->assertSame('Author sent “About us” (Pages) for sign-off, and it’s waiting for Legal.', $lines[0]);
            $this->assertContains('Their note: “Check the prices”', $lines);
            $this->assertSame('Review the entry', $email->actionText);
            $this->assertSame($page->editUrl(), $email->actionUrl);

            return true;
        });
    }

    #[Test]
    public function peoples_words_cant_become_links_or_markup_in_emails(): void
    {
        $sneaky = '[Verify your account](https://example.org/phish) <b>now</b> **please** # _x_ ![i](https://example.org/i.png)';

        $page = $this->draft($sneaky);
        $this->author()->set('name', '[Author](https://example.org/a)')->save();
        $this->signOff()->submit($this->author(), $page, $sneaky);
        $this->signOff()->reject($this->lawyer(), $page, $sneaky);

        foreach ([[ReviewNeeded::class, $this->lawyer()], [EntrySentBack::class, $this->author()]] as [$class, $user]) {
            Notification::assertSentTo($user, $class, function ($notification) use ($user, $sneaky) {
                $mail = $notification->toMail($user);
                $html = (string) $mail->render();
                $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

                $this->assertStringNotContainsString('example.org', implode(' ', $this->links($html)));
                $this->assertStringNotContainsString('<img', preg_replace('/<img[^>]+(logo|statamic)[^>]*>/i', '', $html));
                $this->assertStringNotContainsString('<b>now</b>', $html);
                $this->assertStringNotContainsString('<strong>please</strong>', $html);
                // Read as written, in both parts.
                $this->assertStringContainsString('[Verify your account](https://example.org/phish)', html_entity_decode(strip_tags($html)));
                $this->assertStringContainsString($sneaky, $text);

                return true;
            });
        }
    }

    protected function links(string $html): array
    {
        preg_match_all('/<a [^>]*href="([^"]+)"/', $html, $matches);

        return $matches[1];
    }
}
