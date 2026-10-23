<!DOCTYPE html>
<html><body style="font-family: sans-serif; max-width: 480px; padding: 24px;">
<h2>Khan Enterprises employee portal</h2>
<p>Hi {{ $name }},</p>
<p>An administrator has {{ $isReset ? 'requested a password reset' : 'created an account' }} for {{ $email }}.</p>
<p><a href="{{ $setupUrl }}">Choose your password</a></p>
<p>This link expires in 60 minutes and can be used once. Existing login sessions have been revoked. Do not forward this link.</p>
</body></html>
