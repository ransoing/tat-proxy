<?php

/**
 * The high-level code for the createPostOutreachReport API call.
 * See index.php for usage details.
 * 
 * Modifies an Outreach Location object, marking it as complete and adding some details.
 */

require_once( '../functions.php' );
require_once( '../api-support-functions.php' );

// verify the firebase login and get the user's firebase uid.
$firebaseUid = verifyFirebaseLogin();
$postData = getPOSTData();

addToLog( 'command: createPostOutreachReport. POST data received:', $postData );

// sanitize outreachLocationId by removing quotes
$postData->outreachLocationId = str_replace( array("'", '"'), "", $postData->outreachLocationId );

getSalesforceContactID( $firebaseUid )->then( function($contactID) use ($postData) {

    $miscAnswers = formatQAs(
        array( 'Other accomplishments:', (isset($postData->otherAccomplishments) ? $postData->otherAccomplishments : '') ),
        array( 'Do you plan to follow up with your contact?', $postData->willFollowUp ? 'Yes' : 'No' ),
        array( 'When will you follow up?', $postData->followUpDate )
    );

    $sfData = array(
        'Is_Completed__c' => true,
        'Completion_Date__c' => $postData->completionDate,
        'Total_Man_Hours__c' => $postData->totalHours,
        'Accomplishments__c' => implode( ';', $postData->accomplishments ),
        'Post_Outreach_Report_Submitted_By__c' => $contactID,
        'Misc_Post_Outreach_Report_Answers__c' => $miscAnswers
    );

    return makeSalesforceRequestWithTokenExpirationCheck( function() use ($sfData, $postData, $contactID) {
        // modify the outreach location
        logSection( 'Changing the given outreach location' );
        return salesforceAPIPatchAsync( 'sobjects/TAT_App_Outreach_Location__c/' . $postData->outreachLocationId, $sfData );
    })->then( function() use ($postData, $contactID) {
        // get outreach location info and volunteer Contact info
        $locationFields = array( 'Id', 'Name', 'Contact_Email__c', 'Contact_First_Name__c', 'Contact_Last_Name__c', 'Contact_Phone__c', 'Contact_Title__c', 'Country__c', 'State__c', 'City__c', 'Street__c', 'Zip__c', 'Type__c', 'Campaign__c' );
        $locationFieldsString = implode( ',', $locationFields );
        $contactFields = array( 'FirstName', 'LastName' );
        $contactFieldsString = implode( ',', $contactFields );
        logSection( 'Getting info on the outreach location and the volunteer\'s Contact entry' );
        return \React\Promise\all( array(
            salesforceAPIGetAsync( 'sobjects/TAT_App_Outreach_Location__c/' . $postData->outreachLocationId, array('fields' => $locationFieldsString) ),
            salesforceAPIGetAsync( 'sobjects/Contact/' . $contactID, array('fields' => $contactFieldsString) )
        ));
    })->then( function($responses) use ($postData) {
        $outreachLocation = $responses[0];
        $volunteerContact = $responses[1];
        $volunteerName = $volunteerContact->FirstName . ' ' . $volunteerContact->LastName;


        return promiseToGetCampaignOwner( $outreachLocation->Campaign__c )->then( function($campaignOwner) use ($outreachLocation, $postData, $volunteerName) {
            // not all http calls depend on previous http calls. separate them into various 'threads' that can be performed simultaneously
            return \React\Promise\all( array(
                promiseToChangeCampaignOpportunity( $outreachLocation->Campaign__c, floatval($postData->totalHours) ),
                promiseToMakeAccountAndContact( $postData, $outreachLocation, $campaignOwner->Id ),
            ))->then( function($promiseResults) use ($outreachLocation, $postData, $campaignOwner, $volunteerName) {

                $accountId = $promiseResults[1]->accountId;
                $contactId = $promiseResults[1]->contactId;

                // create new opportunities in salesforce depending on the specific accomplishments made. This must be done after the
                // new Contact has been created, because the opportunities need to point to this Contact
                $otherAccomplishments = getProperty( $postData, 'otherAccomplishments', '' );
                return promiseToCreateOpportunities(
                    $accountId, $contactId, $volunteerName, $postData->accomplishments, $campaignOwner->Id, $outreachLocation, $otherAccomplishments
                )->then( function($opportunities) use ($outreachLocation, $accountId, $contactId, $campaignOwner, $postData) {

                    // the email address is the 'Username' field of the campaign owner User object
                    sendResultsEmail( $campaignOwner->Username, $accountId, $contactId, $opportunities, $outreachLocation, $postData );
                    return true;
                });
            });
        });
    });
})->then( function() {
    echo '{"success": true}';
})->otherwise(
    $handleRequestFailure
);

$loop->run();


/**
 * Finds an opportunity related to a campaign, and changes its stage to "Closed/Won".
 * @param $campaignId {string} - salesforce Campaign object ID
 * @param $numVolunteerHours {number} - the number of hours spent on the outreach represented by this post outreach report
 * @return - a Promise which resolves with `true`
 */
function promiseToChangeCampaignOpportunity( $campaignId, $numVolunteerHours ) {
    logSection( 'Getting info on the Opportunity' );
    return getAllSalesforceQueryRecordsAsync(
        "SELECT Id, Hours_volunteered__c FROM Opportunity WHERE Volunteer_Event_Campaign__c = '{$campaignId}'"
    )->then( function($records) use ($numVolunteerHours) {
        // change the Opportunity stage to Closed/Won, and add to the number of volunteer hours
        if ( sizeof($records) > 0 ) {
            $currentHours = floatval( $records[0]->Hours_volunteered__c );
            $patchData = array(
                'StageName' => 'Closed/Won',
                'Hours_volunteered__c' => $currentHours + $numVolunteerHours
            );
            logSection( 'Updating the Opportunity' );
            return salesforceAPIPatchAsync( 'sobjects/Opportunity/' . $records[0]->Id, $patchData );
        } else {
            return true;
        }
    });
}


/**
 * Creates a new Account in salesforce based on POST fields (unless one with the submitted info already exists).
 * Also creates a new Contact based on POST fields.
 * @param $postData {object}
 * @param $outreachLocation {object} - an instance of the TAT_App_Outreach_Location__c object in salesforce
 * @param $campaignOwnerId {string} - a string representing the ID of the User in salesforce who should own the new Account and Contact
 * @return - a Promise which resolves with an object that has `accountId` and `contactId` properties
 */
function promiseToMakeAccountAndContact( $postData, $outreachLocation, $campaignOwnerId ) {
    // search to see if this account already exists.
    logSection( 'Searching to see if an Account already exists for the outreach location' );
    return getAllSalesforceQueryRecordsAsync( sprintf(
        "SELECT Id FROM Account WHERE Name = '%s' AND BillingState = '%s' AND BillingCity = '%s' AND BillingStreet = '%s'",
        escapeSingleQuotes($outreachLocation->Name),
        escapeSingleQuotes($outreachLocation->State__c),
        escapeSingleQuotes($outreachLocation->City__c),
        escapeSingleQuotes($outreachLocation->Street__c)
    ))->then( function($records) use ($postData, $outreachLocation, $campaignOwnerId) {
        // ultimately return the ID of an Account; either a new one or one that already exists

        if ( sizeof($records) > 0 ) {
            // use this account.
            return $records[0]->Id;
        }

        // create a new account

        // mapping from Outreach Location type to Account type
        $typeMapping = array(
            'cdlSchool' => 'CDL School',
            'truckingCompany' => 'Trucking Company',
            'truckStop' => 'Truck Stop/Travel Plaza'
        );

        $fields = array(
            'Name' => $outreachLocation->Name,
            'OwnerId' => $campaignOwnerId,
            'Type' => $typeMapping[ $outreachLocation->Type__c ],
            'BillingCountry' => $outreachLocation->Country__c,
            'BillingStreet' => $outreachLocation->Street__c,
            'BillingCity' => $outreachLocation->City__c,
            'BillingState' => $outreachLocation->State__c,
            'BillingPostalCode' => $outreachLocation->Zip__c
        );
        logSection( 'Creating a new Account' );
        return salesforceAPIPostAsync( 'sobjects/Account', $fields )->then( function($newAccount) {
            return $newAccount->id;
        });

    })->then( function($accountId) use ($postData, $outreachLocation, $campaignOwnerId) {

        // create a Contact associated with the account. This must happen after the account is created, because we need to insert
        // the right AccountId when this contact is created, not after
        $fields = array(
            'FirstName' => $postData->contactFirstName,
            'LastName' => $postData->contactLastName,
            'Title' => $postData->contactTitle,
            'npe01__Preferred_Email__c' =>  'Work',
            'npe01__PreferredPhone__c' => 'Work',
            'npe01__Primary_Address_Type__c' => 'Work',
            'MailingCountry' => $outreachLocation->Country__c,
            'MailingStreet' => $outreachLocation->Street__c,
            'MailingCity' => $outreachLocation->City__c,
            'MailingState' => $outreachLocation->State__c,
            'MailingPostalCode' => $outreachLocation->Zip__c,
            'AccountId' => $accountId,
            'OwnerId' => $campaignOwnerId
        );
        if ( isset($postData->contactEmail) && !empty($postData->contactEmail) ) {
            $fields['npe01__WorkEmail__c'] = $postData->contactEmail;
        }
        if ( isset($postData->contactPhone) && !empty($postData->contactPhone) ) {
            $fields['npe01__WorkPhone__c'] = $postData->contactPhone;
        }

        logSection( 'Updating some info on the volunteer\'s Contact' );
        return salesforceAPIPostAsync( 'sobjects/Contact', $fields )->then( function($newContact) use ($accountId) {
            // edit the Account to have the Contact we just created as the primary contact
            logSection( 'Updating the Account to add info regarding the primary contact' );
            return salesforceAPIPatchAsync(
                'sobjects/Account/' . $accountId, array('npe01__One2OneContact__c' => $newContact->id)
            )->then( function() use ($accountId, $newContact) {
                return (object)array(
                    'accountId' => $accountId,
                    'contactId' => $newContact->id
                );
            });
        });
    });
}


/**
 * Creates multiple new Opportunities in salesforce, based on the reported accomplishments.
 * @param $accountId {string} - salesforce Account object ID, representing the Account for the location visited
 * @param $contactId {string} - salesforce Contact object ID, representing the primary Contact for the location visited
 * @param $volunteerName {string} - the name of the volunteer who visited the location
 * @param $accomplishments {string[]} - an array of special keywords representing specific accomplishments, or descriptions of accomplishments
 * @param $campaignOwnerId {object} - salesforce User object ID
 * @param $outreachLocation {object} - an instance of the TAT_App_Outreach_Location__c object in salesforce
 * @param $otherAccomplishments {string} - A potentially long string describing some accomplishment
 * @return - a Promise which resolves with an array of objects representing the new Opportunities. Each has a property `id`
 */
function promiseToCreateOpportunities( $accountId, $contactId, $volunteerName, $accomplishments, $campaignOwnerId, $outreachLocation, $otherAccomplishments = '' ) {
    $newOpps = array();
    $oneMonthFromToday = new DateTime();
    $oneMonthFromToday->add( new DateInterval('P1M') );
    $inOneMonthDate = $oneMonthFromToday->format( 'm/d/Y' );
    $inOneMonthISO = $oneMonthFromToday->format( 'c' );
    $volunteerNote = 'Volunteer who produced this opportunity: ' . $volunteerName;

    // the "Opportunity Record Type" field has a data type of "Record Type", which is some kind of object of its own.
    // We're interested in using only a few instances of "Record Type". The IDs of these instances are defined below.
    $oppRecordTypes = (object)array(
        'distributionPoint' => '012o0000000o2YcAAI',
        'registeredTatTrained' => '012o0000000o2WMAAY',
        'otherInvolvement' => '012o0000000o2WWAAY'
    );

    // create an array with fields and values that are common to all types of opportunities.
    // if-blocks will merge some data with this array
    $defaultOpp = array(
        'attributes' => array( 'type' => 'Opportunity' ),
        'AccountId' => $accountId,
        'npsp__Primary_Contact__c' => $contactId,
        'CloseDate' => $inOneMonthISO,
        'OwnerId' => $campaignOwnerId,
        'CampaignId' => $outreachLocation->Campaign__c,
        'StageName' => 'Prospecting',
        'Description' => $volunteerNote
    );

    // using a for-loop instead of array_map has the benefit of filtering out invalid values of `$accomplishments`
    foreach ( $accomplishments as $accomplishment ) {
        if ( empty($accomplishment) ) {
            continue;
        }

        if (
            $outreachLocation->Type__c === 'truckStop' && $accomplishment === 'willTrainEmployees' ||
            $outreachLocation->Type__c === 'cdlSchool' && $accomplishment === 'willUseTatTraining' ||
            $outreachLocation->Type__c === 'truckingCompany' && $accomplishment === 'willTrainDrivers'
        ) {
            // create a "Registered TAT Trained" opportunity
            array_push( $newOpps, array_merge($defaultOpp, array(
                'RecordTypeId' => $oppRecordTypes->registeredTatTrained,
                'Name' => $outreachLocation->Name . ' - Registered TAT Trained - ' . $inOneMonthDate,
                'Probability' => 100,
                'Total_trained__c' => 0
            )));

        } else if (
            $outreachLocation->Type__c === 'truckStop' && $accomplishment === 'willDistributeMaterials'
        ) {
            // create a "Distribution Point" opportunity
            array_push( $newOpps, array_merge($defaultOpp, array(
                'RecordTypeId' => $oppRecordTypes->distributionPoint,
                'Name' => $outreachLocation->Name . ' - Distribution Point - ' . $inOneMonthDate,
                'Probability' => 100,
                'Location_Type__c' => 'Truck stops'
            )));
        }
    }

    if ( !empty($otherAccomplishments) ) {
        // create an "Other Involvement" opportunity
        array_push( $newOpps, array_merge($defaultOpp, array(
            'RecordTypeId' => $oppRecordTypes->otherInvolvement,
            'Name' => $outreachLocation->Name . ' - OI: from Vol Dis Outreach - ' . $inOneMonthDate,
            'Description' => $otherAccomplishments . "\n\n" . $volunteerNote,
            'Probability' => 0
        )));
    }


    if ( sizeof($newOpps) > 0 ) {
        logSection( 'Creating new Opportunities' );
        return salesforceAPIPostAsync( 'composite/sobjects/', array(
            'allOrNone' => true,
            'records' => $newOpps
        ))->then( function($responses) {
            // check that the request was successful
            if ( !$responses[0]->success ) {
                throw new Exception( 'Failed to create opportunities.\n' . json_encode($responses) );
            }
            return $responses;
        });
    } else {
        return new React\Promise\FulfilledPromise( array() );
    }
}


/**
 * Sends the results of the post-outreach report to an email address.
 * @param $toAddress {string}
 * @param $accountId {string} - salesforce Account object ID
 * @param $contactId {string} - salesforce Contact object ID
 * @param $opportunities {object[]} - an array of objects, each of which must have a property `id`
 * @param $outreachLocation {object} - an instance of the TAT_App_Outreach_Location__c object in salesforce
 * @param $postData {object} - all the data sent via POST
 */
function sendResultsEmail( $toAddress, $accountId, $contactId, $opportunities, $outreachLocation, $postData ) {
    // build an email to send regarding the results.
    $instanceUrl = getSFAuth()->instance_url;
    $opps = array_map( function($opportunity, $i) use($instanceUrl) {
        return "<p><a href='{$instanceUrl}/lightning/r/Opportunity/{$opportunity->id}/view'>Opportunity " . ($i+1) . " </a><p>";
    }, $opportunities, array_keys($opportunities) );

    $emailContent = "<p>Outreach completed at {$outreachLocation->Name}. View the <a href='{$instanceUrl}/lightning/r/TAT_App_Outreach_Location__c/{$outreachLocation->Id}/view'>TAT App Outreach Location</a> "
        . " in Salesforce to see the responses to the post-outreach report.</p>"
        . "<p>View the <a href='{$instanceUrl}/lightning/r/Account/{$accountId}/view'>Account</a> related to this location<p>"
        . "<p>View the <a href='{$instanceUrl}/lightning/r/Contact/{$contactId}/view'>primary Contact</a> for the Account<p>"
        . implode( '', $opps );
    sendMail( $toAddress, 'Post-outreach report completed', $emailContent );
}
