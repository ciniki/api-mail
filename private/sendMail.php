<?php
//
// Description
// -----------
//
// Arguments
// ---------
// ciniki:
// business_id:     The ID of the business to the mail belongs to.
// mail_id:         The ID of the mail message to send.
// 
// Returns
// -------
//
function ciniki_mail_sendMail($ciniki, $business_id, &$settings, $mail_id) {

    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbQuote');
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbHashQuery');
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbUpdate');
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbAddModuleHistory');

    //
    // This function is run after the API has returned status, or from cron,
    // so all errors should be send to mail log
    //
    ciniki_core_loadMethod($ciniki, 'ciniki', 'mail', 'private', 'logMsg');
    
    //
    // Query for mail details
    //
    $strsql = "SELECT id, status, "
        . "mailing_id, survey_invite_id, "
        . "customer_id, customer_name, customer_email, "
        . "subject, html_content, text_content "
        . "FROM ciniki_mail "
        . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $mail_id) . "' "
        . "AND business_id = '" . ciniki_core_dbQuote($ciniki, $business_id) . "' "
        . "AND status >= 10 "
        . "";
    $rc = ciniki_core_dbHashQuery($ciniki, $strsql, 'ciniki.mail', 'mail');
    if( $rc['stat'] != 'ok' ) {
        return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.51', 'msg'=>'Unable to find message',
            'mail_id'=>$mail_id, 'severity'=>50, 'err'=>$rc['err'],
            ));
    }
    if( !isset($rc['mail']) ) {
        return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.52', 'msg'=>'Message does not exist.',
            'mail_id'=>$mail_id, 'severity'=>50, 
            ));
    }
    $email = $rc['mail'];

    //
    // Check for attachments
    //
    $strsql = "SELECT id, filename, content "
        . "FROM ciniki_mail_attachments "
        . "WHERE ciniki_mail_attachments.business_id = '" . ciniki_core_dbQuote($ciniki, $business_id) . "' "
        . "AND ciniki_mail_attachments.mail_id = '" . ciniki_core_dbQuote($ciniki, $email['id']) . "' "
        . "";
    $rc = ciniki_core_dbHashQuery($ciniki, $strsql, 'ciniki.mail', 'attachment');
    if( $rc['stat'] != 'ok' ) {
        return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.53', 'msg'=>'Unable to find attachments for the message',
            'mail_id'=>$mail_id, 'severity'=>50, 'err'=>$rc['err'],
            ));
    }
    if( isset($rc['rows']) ) {
        $email['attachments'] = $rc['rows'];
    }

    //
    // Set timezone
    //
    date_default_timezone_set('UTC');

    //
    // Check if we can lock the message, by updating to status 20
    //
    $strsql = "UPDATE ciniki_mail SET status = 20, last_updated = UTC_TIMESTAMP() "
        . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $mail_id) . "' "
        . "AND business_id = '" . ciniki_core_dbQuote($ciniki, $business_id) . "' "
        . "";
    $rc = ciniki_core_dbUpdate($ciniki, $strsql, 'ciniki.mail');
    if( $rc['stat'] != 'ok' ) {
        return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.54', 'msg'=>'Unable to aquire lock.', 'pmsg'=>'Failed to update status=20',
            'mail_id'=>$mail_id, 'severity'=>50, 'err'=>$rc['err'],
            ));
    }
    if( $rc['num_affected_rows'] < 1 ) {
        return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.55', 'msg'=>'Unable to aquire lock.', 'pmsg'=>'No rows updated',
            'mail_id'=>$mail_id, 'severity'=>50, 
            ));
    }
    ciniki_core_dbAddModuleHistory($ciniki, 'ciniki.mail', 'ciniki_mail_history', $business_id, 
        2, 'ciniki_mail', $mail_id, 'status', '20');

    //
    // Send via mailgun
    //
    if( isset($settings['mailgun-domain']) && $settings['mailgun-domain'] != '' 
        && isset($settings['mailgun-key']) && $settings['mailgun-key'] != '' 
        ) {
        //
        // Setup the message
        //
        $msg = array(
            'from' => $settings['smtp-from-name'] . ' <' . $settings['smtp-from-address'] . '>',
            'subject' => $email['subject'],
            'html' => $email['html_content'],
            'text' => $email['text_content'],
//            'subject' => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $email['subject']),
//            'html' => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $email['html_content']),
//            'text' => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $email['text_content']),
            );
        if( isset($ciniki['config']['ciniki.mail']['force.mailto']) ) {
            $msg['to'] = $email['customer_name'] . ' <' . $ciniki['config']['ciniki.mail']['force.mailto'] . '>';
            $msg['subject'] .= ' [' . $email['customer_email'] . ']';
        } else {
            $msg['to'] = $email['customer_name'] . ' <' . $email['customer_name'] . '>';
        }

        //
        // Add attachments
        //
        $file_index = 1;
        if( isset($email['attachments']) ) {
            foreach($email['attachments'] as $attachment) {
                $tmpname = tempnam(sys_get_temp_dir(), 'mailgun');
                file_put_contents($tmpname, $attachment['content']);
                $cfile = curl_file_create($tmpname);
                $cfile->setPostFilename($attachment['filename']);
                $msg['file_' . $file_index] = $cfile;
                $file_index++;
            }
        }

        //
        // Send to mailgun api
        //
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $settings['mailgun-key']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'UTF-8');
        curl_setopt($ch, CURLOPT_URL, 'https://api.mailgun.net/v3/' . $settings['mailgun-domain'] . '/messages');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $msg);

        $rsp = json_decode(curl_exec($ch));

        $info = curl_getinfo($ch);
        if( $info['http_code'] != 200 ) {
            error_log("MAIL-ERR: [" . $user['email'] . "] " . print_r($rsp, true));
            return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.users.68', 'msg'=>'Unable to send email: ' . (isset($rsp['message']) ? $rsp['message'] : '')));
        }
        curl_close($ch);
    } 
   
    //
    // Send using PHPMailer and SMTP
    //
    else {
        //  
        // The from address can be set in the config file.
        //  
        require_once($ciniki['config']['ciniki.core']['lib_dir'] . '/PHPMailer/class.phpmailer.php');
        require_once($ciniki['config']['ciniki.core']['lib_dir'] . '/PHPMailer/class.smtp.php');

        $mail = new PHPMailer;

        $mail->SMTPOptions = array(
            'tls'=>array(
                'verify_peer'=> false,
                'verify_peer_name'=> false,
                'allow_self_signed'=> true,
            ),
            'ssl'=>array(
                'verify_peer'=> false,
                'verify_peer_name'=> false,
                'allow_self_signed'=> true,
            ),
        );

        $mail->IsSMTP();
        $use_config = 'yes';
        if( isset($settings['smtp-servers']) && $settings['smtp-servers'] != ''
            ) {
            $mail->Host = $settings['smtp-servers'];
            if( isset($settings['smtp-username']) && $settings['smtp-username'] != '' ) {
                $mail->SMTPAuth = true;
                $mail->Username = $settings['smtp-username'];
                $mail->Password = $settings['smtp-password'];
            }
            if( isset($settings['smtp-secure']) && $settings['smtp-secure'] != '' ) {
                $mail->SMTPSecure = $settings['smtp-secure'];
            }
            if( isset($settings['smtp-port']) && $settings['smtp-port'] != '' ) {
                $mail->Port = $settings['smtp-port'];
            }
            $mail->From = $settings['smtp-from-address'];
            $mail->FromName = $settings['smtp-from-name'];
        } else {
            $mail->Host = $ciniki['config']['ciniki.core']['system.smtp.servers'];
            $mail->SMTPAuth = true;
            $mail->Username = $ciniki['config']['ciniki.core']['system.smtp.username'];
            $mail->Password = $ciniki['config']['ciniki.core']['system.smtp.password'];
            $mail->SMTPSecure = $ciniki['config']['ciniki.core']['system.smtp.secure'];
            $mail->Port = $ciniki['config']['ciniki.core']['system.smtp.port'];

            $mail->From = $ciniki['config']['ciniki.core']['system.email'];
            $mail->FromName = $ciniki['config']['ciniki.core']['system.email.name'];
        }


    //  $mail->SMTPAuth = true;
    //  $mail->Username = $ciniki['config']['ciniki.core']['system.smtp.username'];
    //  $mail->Password = $ciniki['config']['ciniki.core']['system.smtp.password'];

        $mail->IsHTML(true);
        $mail->Subject = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $email['subject']);
        $mail->Body = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $email['html_content']);
        $mail->AltBody = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $email['text_content']);

        if( isset($ciniki['config']['ciniki.mail']['force.mailto']) ) {
            $mail->AddAddress($ciniki['config']['ciniki.mail']['force.mailto'], $email['customer_name']);
            $mail->Subject .= ' [' . $email['customer_email'] . ']';
        } else {
            $mail->AddAddress($email['customer_email'], $email['customer_name']);
        }

        //
        // Add attachments
        //
        if( isset($email['attachments']) ) {
            foreach($email['attachments'] as $attachment) {
                $mail->addStringAttachment($attachment['content'], $attachment['filename']);
            }
        }

        if( !$mail->Send() ) {
            ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.57', 'msg'=>'Unable to send message, trying again.', 'pmsg'=>$mail->ErrorInfo,
                'mail_id'=>$mail_id, 'severity'=>30,
                ));
            sleep(3);
            if( !$mail->Send() ) {  
                //
                // Update the mail status to failed
                //
                $strsql = "UPDATE ciniki_mail SET status = 50, last_updated = UTC_TIMESTAMP() "
                    . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $mail_id) . "' "
                    . "AND business_id = '" . ciniki_core_dbQuote($ciniki, $business_id) . "' "
                    . "";
                $rc = ciniki_core_dbUpdate($ciniki, $strsql, 'ciniki.mail');
                if( $rc['stat'] != 'ok' ) {
                    return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.58', 'msg'=>'Unable to send message and unable to unlock.', 'pmsg'=>'Could not set status=50',
                        'mail_id'=>$mail_id, 'severity'=>50, 'err'=>$rc['err'],
                        ));
                }
                ciniki_core_dbAddModuleHistory($ciniki, 'ciniki.mail', 'ciniki_mail_history', $business_id, 
                    2, 'ciniki_mail', $mail_id, 'status', '50');
                return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.59', 'msg'=>'Unable to send message.', 'pmsg'=>$mail->ErrorInfo,
                    'mail_id'=>$mail_id, 'severity'=>50,
                    ));
            }
        }
        ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'0', 'msg'=>'Message sent.', 'mail_id'=>$mail_id, 'severity'=>10,));
    }

    //
    // Update the mail status
    //
    $utc_datetime = strftime("%Y-%m-%d %H:%M:%S");
    $strsql = "UPDATE ciniki_mail SET status = 30, date_sent = '" . ciniki_core_dbQuote($ciniki, $utc_datetime) . "', last_updated = UTC_TIMESTAMP() "
        . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $mail_id) . "' "
        . "AND business_id = '" . ciniki_core_dbQuote($ciniki, $business_id) . "' "
        . "";
    $rc = ciniki_core_dbUpdate($ciniki, $strsql, 'ciniki.mail');
    if( $rc['stat'] != 'ok' ) {
        return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.60', 'msg'=>'Message send, unable to unlock.', 'pmsg'=>'Could not set status=30',
            'mail_id'=>$mail_id, 'severity'=>40, 'err'=>$rc['err'],
            ));
    }
    ciniki_core_dbAddModuleHistory($ciniki, 'ciniki.mail', 'ciniki_mail_history', $business_id, 
        2, 'ciniki_mail', $mail_id, 'status', '30');
    ciniki_core_dbAddModuleHistory($ciniki, 'ciniki.mail', 'ciniki_mail_history', $business_id, 
        2, 'ciniki_mail', $mail_id, 'date_sent', $utc_datetime);

    //
    // Update the survey invite
    //
    if( $email['survey_invite_id'] > 0 ) {
        $strsql = "UPDATE ciniki_survey_invites SET status = 10, date_sent = '" . ciniki_core_dbQuote($ciniki, $utc_datetime) . "', last_updated = UTC_TIMESTAMP() "
            . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $email['survey_invite_id']) . "' "
            . "AND business_id = '" . ciniki_core_dbQuote($ciniki, $business_id) . "' "
            . "AND status = 5 "
            . "";
        $rc = ciniki_core_dbUpdate($ciniki, $strsql, 'ciniki.surveys');
        if( $rc['stat'] != 'ok' ) {
            return ciniki_mail_logMsg($ciniki, $business_id, array('code'=>'ciniki.mail.56', 'msg'=>'Unable to update survey.', 'pmsg'=>'Could not set survey status=10',
                'mail_id'=>$mail_id, 'severity'=>40, 'err'=>$rc['err'],
                ));
        }
        if( $rc['num_affected_rows'] > 0 ) {
            ciniki_core_dbAddModuleHistory($ciniki, 'ciniki.surveys', 'ciniki_survey_history', $business_id, 
                2, 'ciniki_survey_invites', $email['survey_invite_id'], 'status', '10');
            ciniki_core_dbAddModuleHistory($ciniki, 'ciniki.surveys', 'ciniki_survey_history', $business_id, 
                2, 'ciniki_survey_invites', $email['survey_invite_id'], 'date_sent', $utc_datetime);
        }
    }

    //
    // FIXME: Check for hooks to update other modules
    //

    return array('stat'=>'ok');
}
