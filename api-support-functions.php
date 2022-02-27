<?php

require_once( __DIR__ . '/error-handling.php' );

use Kreait\Firebase;

$jsonCacheFilepath = __DIR__ . '/contact-ids.json';
$sqliteCacheFilepath = __DIR__ . '/contact-ids.sqlite';
$gServiceAccountCredentialsFilepath = __DIR__ . '/google-service-account.json';

// To support CORS, return 200 for HEAD or OPTIONS requests.
if ( $_SERVER['REQUEST_METHOD'] === 'HEAD' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
    http_response_code( 200 );
    exit;
}

if ( !function_exists('getallheaders') ) {
    function getallheaders() {
        $headers = [];
        foreach ( $_SERVER as $name => $value ) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            } else {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = $value;
            }
        }
        return $headers;
    }
}


// gets POST data sent as either JSON or as form-encoded data.
// returns an object, or exits the script with an error.
function getPOSTData() {
    // check headers for one of two specific content-types
    $headers = getallheaders();
    $headerError = 'You must specify a Content-Type of either `application/x-www-form-urlencoded` or `application/json`';
    if ( !isset($headers['Content-Type']) ) {
        errorExit( 400, $headerError );
    }

    if ( $headers['Content-Type'] === 'application/json' ) {
        // parse the input as json
        $requestBody = file_get_contents( 'php://input' );
        $data = json_decode( $requestBody );
        if ( $data === null ) {
            errorExit( 400, 'Error parsing JSON' );
        }
        return $data;
    } else if  ( $headers['Content-Type'] === 'application/x-www-form-urlencoded' ) {
        // convert the $_POST data from an associative array to an object
        return (object)$_POST;
    } else {
        errorExit( 400, $headerError );
    }
}


/**
 * Checks the POST parameters for a firebase ID token, which is proof of login, and verifies this token against firebase.
 * If there was an error in this verification, the script echoes an error message and quits. Otherwise, it returns the
 * user's firebase user ID.
 */
function verifyFirebaseLogin() {
    $postData = getPOSTData();

    // verify that the required parameters are present
    if ( !isset($postData->firebaseIdToken) ) {
        errorExit( 400, '`firebaseIdToken` must be present in the POST parameters.' );
    }

    // verify against Firebase that the ID token is valid (i.e. it represents a logged-in user)
    $firebaseResponse = firebaseAuthAPIPost(
        'getAccountInfo',
        array( 'idToken' => $postData->firebaseIdToken )
    );
    // check if there was an error with the request itself
    if ( $firebaseResponse['error'] ) {
        errorExit( 400, "The request to Firebase failed to execute: " . $firebaseResponse['error'] );
    }
    // check if there was an error in the response from Firebase
    if ( isset($firebaseResponse['content']->error) ) {
        errorExit( 400, "The request to Firebase returned with an error: " . $firebaseResponse['content']->error->message );
    }

    return $firebaseResponse['content']->users[0]->localId;
}


/**
 * Make some salesforce request(s), and if there was an error, find out if the error was due to an expired access token.
 * If it was, refresh the token and try the request again. Returns a promise which resolves when the original salesforce
 * request is ultimately made successfully, or rejects when the request fails for some reason other than token expiration.
 * @param makeSalesforceRequest A function which returns a promise. This function should make some request to salesforce.
 */
function makeSalesforceRequestWithTokenExpirationCheck( $makeSalesforceRequest ) {
    return $makeSalesforceRequest()->otherwise( function($e) use ($makeSalesforceRequest) {
        // find out if the error was due to an expired token
        if ( method_exists($e, 'getResponse') && !empty($e->getResponse()) && !empty($e->getResponse()->getBody()) ) {
            $response = $e->getResponse();
            $bodyString = (string)$response->getBody();
            $body = getJsonBodyFromResponse( $response );
            // check if the token was expired so we can refresh it
            if (
                $response->getStatusCode() === 401 &&
                isset( $body[0] ) &&
                isset( $body[0]->errorCode ) &&
                $body[0]->errorCode === 'INVALID_SESSION_ID'
            ) {
                // refresh the token.
                return refreshSalesforceTokenAsync()->then( function() use ($makeSalesforceRequest) {
                    // make the original request again.
                    return $makeSalesforceRequest();
                });
            } else {
                throw $e;
            }
        } else {
            throw $e;
        }
    });
}


/**
 * Returns a promise which resolves with a string representing the ContactID from a local cache, or fetches it from salesforce.
 * Rejects with an error message if there is no Contact entry in salesforce that is associated with the given firebaseUid
 */
function getSalesforceContactID( $firebaseUid ) {
    // see if we've already saved the contactID for this firebase user
    $cachedID = getCachedContactID( $firebaseUid );
    if ( $cachedID !== false ) {
        // return a promise with the saved ID
        $deferred = new \React\Promise\Deferred();
        $deferred->resolve( $cachedID );
        return $deferred->promise();
    }

    return makeSalesforceRequestWithTokenExpirationCheck( function() use ($firebaseUid) {
        return getAllSalesforceQueryRecordsAsync( "SELECT Id from Contact WHERE TAT_App_Firebase_UID__c = '$firebaseUid'" );
    })->then( function($queryRecords) use ($firebaseUid) {
        if ( sizeof($queryRecords) === 0 ) {
            // return some expected error so that the app can know when the user is a new user (has no salesforce entry).
            throw new ExpectedException( json_encode((object)array(
                'errorCode' => 'FIREBASE_USER_NOT_IN_SALESFORCE',
                'message' => 'The specified Firebase user does not have an associated Contact entry in Salesforce'
            )));
        }
        $contactID = $queryRecords[0]->Id;
        // write the ID to file so we can avoid this http request in the future
        cacheContactID( $firebaseUid, $contactID );
        return $contactID;
    });
}


/**
 * Returns a string representing the ContactID from a local cache, or returns false if not present.
 */
function getCachedContactID( $firebaseUid ) {
    global $jsonCacheFilepath, $sqliteCacheFilepath;
    // the cache may be saved as a json file, or saved in a sqlite db
    if ( class_exists('SQLite3') && file_exists($sqliteCacheFilepath) ) {
        // load from sqlite
        $db = new SQLite3( $sqliteCacheFilepath );
        $result = $db->query( "SELECT * FROM cache WHERE firebaseUid='$firebaseUid'" );
        $row = $result->fetchArray();
        $db->close();
        if ( $row ) {
            return $row['contactID'];
        }
    } else if ( file_exists($jsonCacheFilepath) ) {
        // load from json file
        $contactIdCache = json_decode( file_get_contents($jsonCacheFilepath) );
        if ( isset($contactIdCache->$firebaseUid) ) {
            return $contactIdCache->$firebaseUid;
        }
    }

    return false;
}

/**
 * Saves a firebaseUid value and associated contactID value to either a SQLite database or a json file, depending on whether
 * SQLite3 is installed.
 */
function cacheContactID( $firebaseUid, $contactID ) {
    global $jsonCacheFilepath, $sqliteCacheFilepath;
    // try saving to sqlite db first, then to a json file
    if ( class_exists('SQLite3') ) {
        $db = new SQLite3( $sqliteCacheFilepath );
        // check if the cache table exists and create it if it doesn't
        $db->exec( "CREATE TABLE IF NOT EXISTS cache (id INTEGER PRIMARY KEY AUTOINCREMENT, firebaseUid TEXT, contactID TEXT)" );
        // add the value to the cache table
        $db->exec( "INSERT INTO cache (firebaseUid, contactID) VALUES ('$firebaseUid', '$contactID')" );
        $db->close();
    } else {
        // read the existing file if there is one
        if ( file_exists($jsonCacheFilepath) ) {
            $contactIdCache = json_decode( file_get_contents($jsonCacheFilepath) );
        } else {
            $contactIdCache = (object)array();
        }
        // add the new cache value to the file
        $contactIdCache->$firebaseUid = $contactID;
        file_put_contents( $jsonCacheFilepath, json_encode($contactIdCache) );
    }
}


/**
 * Makes a GET request to the salesforce API and returns a Promise. Does not atomatically refresh the access token.
 * Resolves with the salesforce response object, or rejects with an error object.
 * For errors, Use ->getMessage() to get the error message or ->getResponse() to get the response object.
 */
function salesforceAPIGetAsync( $urlSegment, $data = array() ) {
    global $browser;
    $sfAuth = getSFAuth();
    $url = $sfAuth->instance_url . '/services/data/v44.0/' . $urlSegment . '.json?' . http_build_query( $data );
    $headers = array( 'Authorization' => 'Bearer ' . $sfAuth->access_token );
    
    addToLog( 'GET: ' . $url );
    addToLog( 'headers:', $headers );

    // add access token to header and make the request
    return $browser->get( $url, $headers )->then( function($response) {
        $responseBody = getJsonBodyFromResponse( $response );
        addToLog( 'response:', $responseBody );
        return $responseBody;
    });
}

/**
 * Makes a POST, DELETE, PUT, or PATCH request to the salesforce API and returns a Promise. Does not atomatically refresh the access token.
 * Resolves with the salesforce response object, or rejects with an error object.
 * For errors, use ->getMessage() to get the error message or ->getResponse() to get the response object.
 * $method should be 'post', 'delete', 'put', or 'patch'
 */
function salesforceAPIAsync( $method, $urlSegment, $data = array() ) {
    global $browser;
    $sfAuth = getSFAuth();
    $url = $sfAuth->instance_url . '/services/data/v44.0/' . $urlSegment;
    $headers = array(
        'Authorization' => 'Bearer ' . $sfAuth->access_token,
        'Content-Type' => 'application/json'
    );

    addToLog( 'request method: ' . $method . ' URL: ' . $url );
    addToLog( 'headers:', $headers );
    addToLog( 'request body:', $data );

    // add access token to header and make the request
    return $browser->$method( $url, $headers, json_encode($data) )->then( function($response) {
        $responseBody = getJsonBodyFromResponse( $response );
        addToLog( 'response:', $responseBody );
        return $responseBody;
    });
}
// convenience functions
function salesforceAPIPostAsync( $urlSegment, $data = array() ) {
    return salesforceAPIAsync( 'post', $urlSegment, $data );
}
function salesforceAPIPatchAsync( $urlSegment, $data = array() ) {
    return salesforceAPIAsync( 'patch', $urlSegment, $data );
}
function salesforceAPIDeleteAsync( $urlSegment, $data = array() ) {
    return salesforceAPIAsync( 'delete', $urlSegment, $data );
}


// performs a SOQL query and returns all records. This may take several requests to the API.
// i.e. getAllSalesforceQueryRecordsAsync( "SELECT Name from Contact WHERE Name LIKE 'S%' OR Name LIKE 'A%' OR Name LIKE 'R%'" )
// remember to properly escape quotes in user-generated variables when building your query
function getAllSalesforceQueryRecordsAsync( $query ) {
    return salesforceAPIGetAsync( 'query/', array('q' => $query) )->then(
        function( $response ) {
            $records = $response->records;
            return getNextRecordsAsync( $response, $records );
        }
    );
}

/**
 * Recursively gets the next set of records in a query.
 */
function getNextRecordsAsync( $response, &$records ) {
    if ( $response->done ) {
        // resolve the promise now
        $deferred = new \React\Promise\Deferred();
        $deferred->resolve( $records );
        return $deferred->promise();
    } else {
        // get the segment of the url after /vXX.X/
        $nextRecordsUrl = $response->nextRecordsUrl;
        $urlSegment = substr( $nextRecordsUrl, strpos($nextRecordsUrl, 'query/') );
        // make request for the next batch
        return salesforceAPIGetAsync($urlSegment)->then( function($nextResponse) use (&$records) {
            // concat the new records with the ones we have so far
            $records = array_merge( $records, $nextResponse->records );
            return getNextRecordsAsync( $nextResponse, $records );
        });
    }
}


function getTeamCoordinators( $accountId ) {
    return getAllSalesforceQueryRecordsAsync(
        "SELECT Id, FirstName, LastName from Contact WHERE TAT_App_Is_Team_Coordinator__c = true AND AccountId = '${accountId}'"
    )->then( function($records) {
        // convert the results to a pleasant format
        $coordinators = array();
        foreach( $records as $record ) {
            array_push( $coordinators, array(
                'name' => "{$record->FirstName} {$record->LastName}",
                'salesforceId' => $record->Id
            ));
        }
        return $coordinators;
    });
}

/**
 * Attempts to refresh the salesforce access token. Returns a promise. Resolves with true on success,
 * or rejects with an error object, which has ->getMessage() and ->getResponse()
 */
function refreshSalesforceTokenAsync() {
    global $browser, $salesforceOAuthBase;
    $sfAuth = getSFAuth();
    $config = getConfig();

    // get a new auth token using the refresh token
    addToLog( 'refreshing salesforce token' );
    return $browser->post(
        "${salesforceOAuthBase}/token",
        array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
        http_build_query( array(
            'grant_type'	=> 'refresh_token',
            'refresh_token'	=> $sfAuth->refresh_token,
            'client_id'     => $config->salesforce->consumerKey,
            'client_secret' => $config->salesforce->consumerSecret,
            'format'		=> 'json'
        ))
    )->then( function($refreshResponse) use ($sfAuth) {
        // save the new access token to disk and to the global variable
        global $sfAuth;
        $refreshBody = getJsonBodyFromResponse( $refreshResponse );
        $sfAuth->access_token = $refreshBody->access_token;
        file_put_contents( __DIR__ . '/sf-auth.json', json_encode($sfAuth) );
        return true;
    });
}

/**
 * Sends a POST request to salesforce, to create a new object. Verifies that the user has a valid ContactID in salesforce.
 * $firebaseUid - the firebase UID of the user
 * $sfUrl - the part of the salesforce API url after /services/data/vXX.X/
 * $sfData - an array of data which will be JSON-encoded and send as POST data. These should be fields on the to-be-created object.
 * $contactIDFieldName - whether to include the user's ContactID in the POST data. If defined, this should be the name of the lookup field in the SF object
 * Returns a promise which resolves with the ID of the newly created object.
 * 
 * example:
 * createNewSFOjbject( 'iojewfoij32', 'sobjects/Contact/', array('Name'=>'Bob') );
 */
function createNewSFObject( $firebaseUid, $sfUrl, $sfData, $contactIDFieldName = false ) {
    // Get the ID of the Contact entry in salesforce
    return getSalesforceContactID( $firebaseUid )->then( function($contactID) use ($sfUrl, $sfData, $contactIDFieldName) {
        // we've now verified that the user has a valid Contact ID in salesforce
        if ( $contactIDFieldName ) {
            $sfData[$contactIDFieldName] = $contactID;
        }
        // create a new object in salesforce
        return makeSalesforceRequestWithTokenExpirationCheck( function() use ($sfUrl, $sfData) {
            return salesforceAPIPostAsync( $sfUrl, $sfData );
        });
    });
}


function getJsonBodyFromResponse( $response ) {
    $body = (string)$response->getBody();
    if ( $body === '' ) {
        // In some cases, the response from salesforce has a blank body. Make it an empty object for consistency.
        $body = '{}';
    }
    $json = json_decode( $body );
    if ( $json === null ) throw new Exception( 'Malformed json.' );
    return $json;
}

// formats questions and answers for submitting to salesforce as an Event activity
// (all survey questions and responses are saved to a single text field)
function formatQA( $question, $answer ) {
    return "> {$question}\n{$answer}";
}

function QASeparator() {
    return "\n\n\n";
}

// takes an undefined number of arguments. Each argument should be an array: [ question, answer ].
// Returns one string with a list of formatted questions and answers.
function formatQAs() {
    $QAs = func_get_args();
    $outputArr = array();
    foreach( $QAs as $QA ) {
        array_push( $outputArr, formatQA($QA[0], $QA[1]) );
    }
    // put a few line breaks between each set of question/answer
    return implode( QASeparator(), $outputArr );
}

function getLocationType( $key ) {
    $locationTypes = array(
        'cdlSchool' => 'CDL School',
        'truckingCompany' => 'Trucking Company',
        'truckStop' => 'Truck Stop'
    );
    return $locationTypes[ $key ];
}

function escapeSingleQuotes( $str ) {
    return str_replace( "'", "\'", $str );
}

// gets the value of an object's property, and optionally returns a default value if the property isn't set
function getProperty( $object, $propertyName, $default = null ) {
	if ( isset($object->$propertyName) ) {
		return $object->$propertyName;
	} else {
	    return $default;
	}
}

/**
 * Gets registration codes which are stored in Firebase database. Only group volunteer distributors have reg codes
 * stored in Salesforce, so other volunteer types have their registration codes stored in firebase.
 * Returns an array with keys 'individual-volunteer-distributors' and 'tat-ambassadors'.
 */
function getSpecialRegistrationCodes() {
    // authenticate as the firebase service account
    global $gServiceAccountCredentialsFilepath;
    $firebaseServiceAccount = Firebase\ServiceAccount::fromJsonFile( $gServiceAccountCredentialsFilepath );
    $firebase = (new Firebase\Factory)->withServiceAccount( $firebaseServiceAccount )->create();
    $fireDatabase = $firebase->getDatabase();
    $regCodes = $fireDatabase->getReference( 'registration-codes' )->getValue();
    return $regCodes;
}

/**
 * Returns an Promise which resolves with an array of the active campaigns for a given contactID.
 * Each array is an object with Id, Name, CreatedDate, EndDate, and IsActive
 */
function getActiveCampaigns( $contactId ) {
    $today = substr( date( 'c' ), 0, 10 );
    return getAllSalesforceQueryRecordsAsync(
        "SELECT Id, Name, CreatedDate, EndDate, IsActive FROM Campaign " .
        "WHERE Id IN (SELECT CampaignId FROM CampaignMember WHERE CampaignMember.ContactId = '{$contactId}') " .
        "AND IsActive = true AND (EndDate = NULL OR EndDate >= {$today})"
    );
}

function addContactToCampaign( $contactId, $campaignId ) {
    return salesforceAPIPostAsync( 'sobjects/CampaignMember/', array(
        'CampaignId' => $campaignId,
        'ContactId' => $contactId
    ))->otherwise( function($e) {
        $body = getJsonBodyFromResponse( $e->getResponse() );
        if ( is_array($body) && sizeof($body) > 0 && $body[0]->errorCode === 'DUPLICATE_VALUE' ) {
            // do nothing. This is fine. The user was already part of the campaign, which is what we wanted in the first place.
        } else {
            // it was some other error that we should pay attention to.
            throw $e;
        }
    });
}

/**
 * Gets the owner of a campaign, as a User salesforce object.
 * @param $campaignId {string} - salesforce Campaign object ID
 * @return - a Promise which resolves with an object that has properties `Username` and `Id`. The "username" is actually an email address.
 */
function promiseToGetCampaignOwner( $campaignId ) {
    logSection( 'Getting owner of campaign' );
    return getAllSalesforceQueryRecordsAsync(
        "SELECT Username, Id FROM User WHERE Id IN (SELECT OwnerId FROM Campaign WHERE Campaign.Id = '{$campaignId}')"
    )->then( function($records) {
        if ( sizeof($records) === 0 ) {
            // not an expected exception
            throw new Exception( 'Failed to get campaign owner.\n' . json_encode($records) );
        }
        return $records[0];
    });
}
