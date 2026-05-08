<?php

function save_ticket_code_image($ticketCode, $format = 'qr') {
    if($format === 'datamatrix') {
        return 'https://barcode.tec-it.com/barcode.ashx?data=' . urlencode($ticketCode) . '&code=DataMatrix';
    }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($ticketCode);
}
