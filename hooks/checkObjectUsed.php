<?php
//
// Description
// -----------
//
// Arguments
// ---------
//
// Returns
// -------
//
function ciniki_mail_hooks_checkObjectUsed($ciniki, $tnid, $args) {

    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbCount');

    // Set the default to not used
    $used = 'no';
    $count = 0;
    $msg = '';

    if( $args['object'] == 'ciniki.customers.customer' ) {
/*        //
        // Check the mail customers
        //
        $strsql = "SELECT 'items', COUNT(*) "
            . "FROM ciniki_mail "
            . "WHERE customer_id = '" . ciniki_core_dbQuote($ciniki, $args['object_id']) . "' "
            . "AND tnid = '" . ciniki_core_dbQuote($ciniki, $tnid) . "' "
            . "";
        $rc = ciniki_core_dbCount($ciniki, $strsql, 'ciniki.mail', 'num');
        if( $rc['stat'] != 'ok' ) {
            return $rc;
        }
        if( isset($rc['num']['items']) && $rc['num']['items'] > 0 ) {
            $used = 'yes';
            $count = $rc['num']['items'];
            $msg .= ($msg!=''?' ':'') . "There " . ($count==1?'is':'are') . " $count mail message" . ($count==1?'':'s') . " for this customer.";
        } */
    }

    //
    // Check for subscriptions
    //
    elseif( $args['object'] == 'ciniki.subscriptions.subscription' ) {
        //
        // NOTE: This is now ignored because the subscriptions/public/subscriptionDelete will remove the entries from
        // ciniki_mailing_subscriptions
        //
/*        $strsql = "SELECT 'items', COUNT(*) "
            . "FROM ciniki_mailing_subscriptions "
            . "WHERE subscription_id = '" . ciniki_core_dbQuote($ciniki, $args['object_id']) . "' "
            . "AND tnid = '" . ciniki_core_dbQuote($ciniki, $tnid) . "' "
            . "";
        $rc = ciniki_core_dbCount($ciniki, $strsql, 'ciniki.mail', 'num');
        if( $rc['stat'] != 'ok' ) {
            return $rc;
        }
        if( isset($rc['num']['items']) && $rc['num']['items'] > 0 ) {
            $used = 'yes';
            $count = $rc['num']['items'];
            $msg .= ($msg!=''?' ':'') . "There " . ($count==1?'is':'are') . " $count mail message" . ($count==1?'':'s') . " for this subscription.";
        } */
    } 

    //
    // Check objrefs
    //
    else {
        $strsql = "SELECT 'items', COUNT(*) "
            . "FROM ciniki_mail_objrefs "
            . "WHERE tnid = '" . ciniki_core_dbQuote($ciniki, $tnid) . "' "
            . "AND object = '" . ciniki_core_dbQuote($ciniki, $args['object']) . "' "
            . "AND object_id = '" . ciniki_core_dbQuote($ciniki, $args['object_id']) . "' "
            . "";
        $rc = ciniki_core_dbCount($ciniki, $strsql, 'ciniki.mail', 'num');
        if( $rc['stat'] != 'ok' ) {
            return $rc;
        }
        if( isset($rc['num']['items']) && $rc['num']['items'] > 0 ) {
            $used = 'yes';
            $count = $rc['num']['items'];
            $msg .= ($msg!=''?' ':'') . "There " . ($count==1?'is':'are') . " $count mail message" . ($count==1?'':'s') . ".";
        }
    }

    return array('stat'=>'ok', 'used'=>$used, 'count'=>$count, 'msg'=>$msg);
}
?>
