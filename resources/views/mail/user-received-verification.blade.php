<div>
    <h1>Hi, {{ $to->name }}</h1>
    <p>
        Your data has been verified by {{ $from->?name}} {{ $from->?surname}}
    </p>

    <p>
        In order to follow your account status, please click on the link below:
    </p>
    <p>
        <a href="{{ env('FRONTEND_URL') }}/account">Check my account</a>
    </p>
    <p>
        Regards, <br>
        {{ config('app.name') }}
    </p>
</div>