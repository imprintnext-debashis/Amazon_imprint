<?php

require_once __DIR__ . "/../vendor/autoload.php";

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
	
    $DEBUG = $_ENV["DEBUG"] === "true";

    /*
	 * Display the Authorize page (GET /)
	 */
	$app->get("/", function(Request $request, Response $response, $args): Response {
	    return $this->get("view")->render($response, "authorize.html");
	});

	/*
	 * Redirect to the Amazon OAuth application authorization page when users submit
	 * the authorization form (POST /)
	 */
	$app->post("/", function(Request $request, Response $response, $args) use ($DEBUG): Response {
	    session_start();
	    $state = bin2hex(random_bytes(256));
	    $_SESSION["spapi_auth_state"] = $state;
	    $_SESSION["spapi_auth_time"] = time();
	    $oauthUrl = "https://sellercentral.amazon.com";
	    $oauthPath = "/apps/authorize/consent";
	    $oauthQueryParams = [
	        "application_id" => $_ENV["SPAPI_APP_ID"],
	        "state" => $state,
	    ];

	    if ($DEBUG) {
	        $oauthQueryParams["version"] = "beta";
	    }

	    $uri = new Uri($oauthUrl);
	    $uri = $uri->withScheme("https")
	               ->withPath($oauthPath);
	    $uri = $uri->withQueryValues($uri, $oauthQueryParams);

	    $response = $response->withHeader("Referrer-Policy", "no-referrer");
	    $response = $response->withHeader("Location", strval($uri));
	    return $response;
	});

	/*
	 * When the user approves the application on Amazon's authorization page, they are redirected
	 * to the URL specified in the application config on Seller Central. A number of query parameters
	 * are passed, including an LWA (Login with Amazon) token which we can use to fetch the  user's
	 * SP API refresh token. With that refresh token, we can generate access tokens that enable us to
	 * make SP API requests on the user's behalf.
	 */
	 $app->get("/redirect", function (Request $request, Response $response, $args): Response {
	    $queryString = $request->getUri()->getQuery();
	    parse_str($queryString, $queryParams);

	    $outerThis = $this;
	    $render = function($params = []) use ($outerThis, $response) {
	        return $outerThis->get("view")->render($response, "redirect.html", $params);
	    };

	    $missing = [];
	    foreach (["state", "spapi_oauth_code", "selling_partner_id"] as $requiredParam) {
	        if (!isset($queryParams[$requiredParam])) {
	            $missing[] = $requiredParam;
	        }
	    }
	    if (count($missing) > 0) {
	        return $render(["err" => true, "missing" => $missing]);
	    }

	    session_start();
	    if (!isset($_SESSION)) {
	        return $render(["err" => true, "no_session" => true]);
	    }
	    if ($queryParams["state"] !== $_SESSION["spapi_auth_state"]) {
	        return $render(["err" => true, "invalid_state"]);
	    }
	    if (time() - $_SESSION["spapi_auth_time"] > 1800) {
	        return $render(["err" => true, "expired" => true]);
	    }

	    [
	        "spapi_oauth_code" => $oauthCode,
	        "selling_partner_id" => $sellingPartnerId
	    ] = $queryParams;

	     $client = new GuzzleHttp\Client();
	    $res = null;

	    try {
	        $res = $client->post("https://api.amazon.com/auth/o2/token", [
	            GuzzleHttp\RequestOptions::JSON => [
	                "grant_type" => "authorization_code",
	                "code" => $oauthCode,
	                "client_id" => $_ENV["LWA_CLIENT_ID"],
	                "client_secret" => $_ENV["LWA_CLIENT_SECRET"],
	            ]
	        ]);
	    } catch (GuzzleHttp\Exception\ClientException $e) {
	        $info = json_decode($e->getResponse()->getBody()->getContents(), true);
	        if ($info["error"] === "invalid_grant") {
	            return $render(["err" => "bad_oauth_token"]);
	        } else {
	            throw $e;
	        }
	    }

	    $body = json_decode($res->getBody(), true);

	    [
	        "refresh_token" => $refreshToken,
	        "access_token" => $accessToken,
	        "expires_in" => $secsTillExpiration,
	    ] = $body;

	    // $queryParams ['refresh_token'] = $refreshToken;
	    // $queryParams ['access_token'] = $accessToken;
	    // $queryParams ['expires_in'] = $secsTillExpiration;

	    file_put_contents("storeData.json", json_encode($queryParams));
	    file_put_contents("storeData1.json", json_encode($body));
	  // save to database here
	    echo "<pre>";
	    header("Location: https://dev.imprintnext.io/multi-vendor-testing/my-account/");die();
	    // return $render($params);
	});

	 // create product from designer studio
	 $app->post("/addProduct", function(Request $request, Response $response, $args) use ($DEBUG): Response {
	    $amazonClientData = json_decode(file_get_contents("storeData.json"), true);
	    $refreshTokenData = json_decode(file_get_contents("storeData1.json"), true);
	    $config = new SellingPartnerApi\Configuration([
		    "lwaClientId" => $_ENV["LWA_CLIENT_ID"],
		    "lwaClientSecret" => $_ENV["LWA_CLIENT_SECRET"],
		    "lwaRefreshToken" => $refreshTokenData['refresh_token'],
		    "awsAccessKeyId" => "AKIASRC776HAXPJRQ57C",
		    "awsSecretAccessKey" => "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",
		    "endpoint" => SellingPartnerApi\Endpoint::NA  // or another endpoint from lib/Endpoints.php
		]);

		$api = new SellingPartnerApi\Api\SellersApi($config);
		try {
		    $result = $api->getMarketplaceParticipations();
		    print_r($result);exit();
		} catch (Exception $e) {
		    echo 'Exception when calling SellersApi->getMarketplaceParticipations: ',
		        $e->getMessage(),
		        PHP_EOL;
		}

		$apiInstance = new SellingPartnerApi\Api\ListingsApi($config);
		$seller_id = $amazonClientData['selling_partner_id']; // string | A selling partner identifier, such as a merchant account or vendor code.
		$sku = 'IM53443543NXT'; // string | A selling partner provided identifier for an Amazon listing.
		$marketplace_ids = 'ATVPDKIKX0DER'; // string[] | A comma-delimited list of Amazon marketplace identifiers for the request.
		$body = new \SellingPartnerApi\Model\Listings\ListingsItemPutRequest(); // \SellingPartnerApi\Model\Listings\ListingsItemPutRequest | The request body schema for the putListingsItem operation.
		$issue_locale = "en_US"; // string | A locale for localization of issues. When not provided, the default language code of the first marketplace is used. Examples: \"en_US\", \"fr_CA\", \"fr_FR\". Localized messages default to \"en_US\" when a localization is not available in the specified locale.

		try {
		    print_r($apiInstance);exit();
		    $result = $apiInstance->putListingsItem($seller_id, $sku, $marketplace_ids, $body, $issue_locale);
		    print_r($result);exit();
		} catch (Exception $e) {
		    echo 'Exception when calling ListingsApi->putListingsItem: ', $e->getMessage(), PHP_EOL;
		}
	    return $response;
	});
};
