<?php

use App\Rules\BdPhone as BdPhoneRule;
use App\Rules\NotDisposableEmail;
use App\Services\CaptchaVerifier;
use App\Support\BdPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

describe('BdPhone', function () {
    it('normalizes every accepted spelling to +8801XXXXXXXXX', function (string $given) {
        expect(BdPhone::normalize($given))->toBe('+8801711223344')->and(BdPhone::isValid($given))->toBeTrue();
    })->with([
        '01711223344', '8801711223344', '+8801711223344', ' 01711223344 ', '017-1122-3344', '+880 1711 223344', '+880-1711-223344', '(0171) 1223344',
    ]);

    it('accepts every mobile operator digit from 3 to 9', function (string $digit) {
        expect(BdPhone::normalize("01{$digit}12345678"))->toBe("+8801{$digit}12345678");
    })->with(['3', '4', '5', '6', '7', '8', '9']);

    it('rejects what is not a Bangladeshi mobile number', function (?string $given) {
        expect(BdPhone::normalize($given))->toBeNull()->and(BdPhone::isValid($given))->toBeFalse();
    })->with([
        'empty' => '', 'null' => null, 'spaces' => '   ', 'short' => '0171122334', 'long' => '017112233445', 'no leading zero' => '1711223344',
        'old operator 011' => '01111223344', 'operator 012' => '01211223344', 'landline' => '0221234567', 'India' => '+919876543210',
        'plus with 0' => '+01711223344', 'double country code' => '+88088017112233', 'letters' => '01711abc344', 'words' => 'call me',
    ]);

    it('works as a validation rule', function () {
        $check = fn (string $phone) => Validator::make(['phone' => $phone], ['phone' => [new BdPhoneRule]])->passes();

        expect($check('+8801711223344'))->toBeTrue()->and($check('12345'))->toBeFalse();
        expect(Validator::make(['phone' => 12345], ['phone' => [new BdPhoneRule]])->errors()->first('phone'))->toBe('Enter a Bangladeshi mobile number, like 01711223344.');
    });
});

describe('NotDisposableEmail', function () {
    it('flags throwaway domains, in any case and on subdomains', function (string $email, bool $disposable) {
        expect(NotDisposableEmail::isDisposable($email))->toBe($disposable);
    })->with([
        ['a@mailinator.com', true], ['A@MAILINATOR.COM', true], ['a@x.y.mailinator.com', true], ['a@yopmail.com', true],
        ['a@gmail.com', false], ['a@everdigitech.com', false], ['a@notmailinator.com', false], ['a@mailinator.com.bd', false], ['no-at-sign', false], ['', false],
    ]);

    it('has a list that is easy to extend', function () {
        $list = config('disposable_domains.domains');

        expect($list)->toBeArray()->not->toBeEmpty()->and(collect($list)->every(fn ($d) => $d === strtolower($d) && ! str_contains($d, '@')))->toBeTrue()
            ->and($list)->toBe(array_values(array_unique($list)));
    });
});

describe('CaptchaVerifier', function () {
    it('passes everything without a secret, and never calls Cloudflare', function () {
        config(['services.turnstile.secret' => '']);
        Http::fake();

        expect(app(CaptchaVerifier::class)->isEnabled())->toBeFalse()->and(app(CaptchaVerifier::class)->verify(null))->toBeTrue()->and(app(CaptchaVerifier::class)->verify('x'))->toBeTrue();
        Http::assertNothingSent();
    });

    it('checks the token with Cloudflare when a secret is set', function () {
        config(['services.turnstile.secret' => 'shh']);
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true])]);

        expect(app(CaptchaVerifier::class)->verify('tok', '203.0.113.5'))->toBeTrue();

        Http::assertSent(fn ($r) => $r->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $r['secret'] === 'shh' && $r['response'] === 'tok' && $r['remoteip'] === '203.0.113.5');
    });

    it('fails closed: rejected, missing, errored and unreachable all fail', function (string $case) {
        config(['services.turnstile.secret' => 'shh']);
        Http::fake(['*' => match ($case) {
            'rejected' => Http::response(['success' => false]),
            'error' => Http::response('', 503),
            'garbage' => Http::response('not json', 200),
            'unreachable' => fn () => throw new ConnectionException('down'),
            default => Http::response(['success' => true]),
        }]);

        expect(app(CaptchaVerifier::class)->verify($case === 'missing' ? null : 'tok'))->toBeFalse();
    })->with(['rejected', 'error', 'garbage', 'unreachable', 'missing']);
});
