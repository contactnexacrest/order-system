<?php
/** D-310: the parts of one approval, shared by the list row and the board card. Expects $__i (an ApprovalsInbox item). */
$__orderPart = $__i['order_id'] !== null
    ? '<a href="/orders/' . (int) $__i['order_id'] . '">' . htmlspecialchars((string) $__i['order_ref']) . '</a>'
    : htmlspecialchars((string) $__i['order_ref']);
$__whoPart = $__orderPart . ((string) $__i['order_ref'] !== '' && (string) $__i['who'] !== '' ? ' · ' : '') . htmlspecialchars((string) $__i['who']);
$__whatPart = '<span class="approval-what' . ($__i['tone'] === 'red' ? ' red' : '') . '">' . htmlspecialchars((string) $__i['what']) . '</span>';
$__button = '<a class="btn-sm" href="' . htmlspecialchars((string) $__i['url']) . '">' . htmlspecialchars((string) $__i['button']) . '</a>';
