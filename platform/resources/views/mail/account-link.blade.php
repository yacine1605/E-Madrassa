Bonjour {{ $name }},

@if ($purpose === 'activation')
{{ $school ? 'L’établissement '.$school.' vous a ouvert un compte.' : 'Un compte vous a été ouvert.' }}
Pour l’activer, ouvrez ce lien dans les 72 heures :
@else
Une réinitialisation de votre mot de passe a été demandée.
Pour choisir un nouveau mot de passe, ouvrez ce lien dans l’heure :
@endif

{{ $url }}

Ce lien ne sert qu’une fois. Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.
