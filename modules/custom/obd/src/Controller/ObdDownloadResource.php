<?php

namespace Drupal\obd\Controller;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Class ObdDownloadResource.
 * Class to handle file downloads from Azure BlobStoraage
 */
class ObdDownloadResource {

  public function downloadResource(Request $request, $res_id, $filename)
  {

    require_once dirname(__FILE__,3) . '/vendor/autoload.php';

    $obd_settings = \Drupal\Core\Site\Settings::get('obd');

    if (!$obd_settings) {

      return new Response(
        'Open by Default settings not available',
        Response::HTTP_INTERNAL_SERVER_ERROR,
        ['content-type' => 'text/html']
      );

    }

    $filepath = 'resources/' . $res_id . '/' . $filename;

    $connectionString = "DefaultEndpointsProtocol=https;
      AccountName={$obd_settings['account_name']};
      AccountKey={$obd_settings['account_key']}";

    try {

      $blobClient = BlobRestProxy::createBlobService($connectionString);
      $blobResult = $blobClient->getBlob($obd_settings['container'], $filepath);
      $source = stream_get_contents($blobResult->getContentStream());
      $tmpFile = tempnam(\Drupal\Core\Site\Settings::get('file_temp_path'),'obd');

      if (file_put_contents($tmpFile, $source)) {

        $response = new BinaryFileResponse($tmpFile);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

      }

      else {

        $response = new Response(
          'Unable to retrieve file. Please try again or contact open-ouvert@tbs-sct.gc.ca to acquire the file',
          Response::HTTP_NOT_FOUND,
          ['content-type' => 'text/html']
        );
      }

    } catch(ServiceException $e){

      $response = new Response();
      $response->setContent($e->getMessage());
      $response->setStatusCode($e->getCode());
      $response->headers->set('Content-Type', 'text/html');

    }

    return $response;

  }

}
