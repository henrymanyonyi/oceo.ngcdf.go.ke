{{--
    The memo's signature blocks, as in the template: the label in bold, "Name: X  Signature: …  Date: …", the designation in bold.
    A signature appears only from a signing record (App\Services\Signing) that still matches the memo as it reads now,
    with the time and the verification code; a signing for an earlier version is said so, without the signature.
--}}
@foreach ($signatures as $s)
    <div class="sign">
        <div class="label">{{ $s['label'] }}:</div>
        <table>
            <tr>
                <td style="width: 42%; white-space: nowrap; padding-right: 8pt;">Name: @if ($s['name'])<strong>{{ $s['name'] }}</strong>@else………………………………@endif</td>
                <td style="width: 33%; white-space: nowrap; padding-right: 8pt;">Signature:
                    @if (($s['signed'] ?? null) && $s['signed']['valid'] && $s['signed']['image'])
                        <img src="{{ $s['signed']['image'] }}" alt="Signature" style="height: 26pt; max-width: 110pt; vertical-align: bottom;">
                    @elseif (($s['signed'] ?? null) && $s['signed']['valid'])
                        <em>Signed in OCEO</em>
                    @else
                        ……………………
                    @endif
                </td>
                <td style="white-space: nowrap;">Date: @if ($s['date']){{ $s['date']->format('d/m/Y') }}@else………………@endif</td>
            </tr>
        </table>
        <div class="des">{{ $s['designation'] }}</div>
        @if ($s['signed'] ?? null)
            @if ($s['signed']['valid'])
                <div style="font-size: 7.5pt; color: #444; margin-top: 1pt;">Digitally signed in OCEO on {{ $s['signed']['at']->format('d/m/Y \a\t H:i') }} after password and code confirmation. Verification code {{ $s['signed']['code'] }}.</div>
            @else
                <div style="font-size: 7.5pt; color: #a00; margin-top: 1pt;">Signed on {{ $s['signed']['at']->format('d/m/Y \a\t H:i') }} for an earlier version of this memo (code {{ $s['signed']['code'] }}). The memo has changed since, so that signature does not apply to this version.</div>
            @endif
        @endif
        @if ($s['note'] ?? null)
            <div style="font-size: 7.5pt; color: #444; margin-top: 1pt;">{{ $s['note'] }}</div>
        @endif
    </div>
@endforeach
