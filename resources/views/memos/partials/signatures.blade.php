{{-- The memo's signature blocks, as in the template: the label in bold, "Name: X  Signature: …  Date: …", the designation in bold. --}}
@foreach ($signatures as $s)
    <div class="sign">
        <div class="label">{{ $s['label'] }}:</div>
        <table>
            <tr>
                <td style="width: 42%; white-space: nowrap; padding-right: 8pt;">Name: @if ($s['name'])<strong>{{ $s['name'] }}</strong>@else………………………………@endif</td>
                <td style="width: 33%; white-space: nowrap; padding-right: 8pt;">Signature: ……………………</td>
                <td style="white-space: nowrap;">Date: @if ($s['date']){{ $s['date']->format('d/m/Y') }}@else………………@endif</td>
            </tr>
        </table>
        <div class="des">{{ $s['designation'] }}</div>
    </div>
@endforeach
