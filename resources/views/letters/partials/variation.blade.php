{{-- Tender Variation: the Details of Variation Requested, as entered. --}}
@if ($letter['variations'])
    <table class="grid">
        <thead>
            <tr>
                <th>Variation Type</th><th>Existing Approved Detail</th><th>Proposed Revised Detail</th><th>Effective Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($letter['variations'] as $row)
                <tr>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ $row['existing'] }}</td>
                    <td>{{ $row['proposed'] }}</td>
                    <td>{{ $row['effective'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
