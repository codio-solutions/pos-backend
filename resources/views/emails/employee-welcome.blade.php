<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, sans-serif; color: #161C1A; max-width: 480px; margin: 0 auto; padding: 24px;">
    <h2 style="margin-bottom: 4px;">
        @if($isReset ?? false) Password reset @else Welcome to Khan Enterprises @endif
    </h2>
    <p style="color: #5C6B65; margin-top: 0;">
        @if($isReset ?? false)
            Your field portal password has been reset by an administrator.
        @else
            Your field portal account is ready.
        @endif
    </p>

    <p>Hi {{ $name }},</p>
    <p>
        @if($isReset ?? false)
            Use this new temporary password to sign in:
        @else
            An account has been created for you on the Khan Enterprises field portal. Use these credentials to sign in:
        @endif
    </p>

    <table style="background: #F1F8F6; border-radius: 6px; padding: 16px; width: 100%; margin: 16px 0;">
        <tr>
            <td style="padding: 4px 0; color: #5C6B65; font-size: 13px;">Email</td>
            <td style="padding: 4px 0; font-weight: 600;">{{ $email }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0; color: #5C6B65; font-size: 13px;">Temporary password</td>
            <td style="padding: 4px 0; font-weight: 600; font-family: monospace;">{{ $password }}</td>
        </tr>
    </table>

    <p>
        <a href="{{ $loginUrl }}" style="background: #0F6B5C; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; display: inline-block;">
            Sign in to the portal
        </a>
    </p>

    <p style="color: #5C6B65; font-size: 13px;">
        We'd recommend changing this password after your first login.
    </p>
</body>
</html>
