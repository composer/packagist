<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Controller;

use Composer\IO\NullIO;
use Composer\Repository\VcsRepository;
use Packagist\WebBundle\Package\Updater;
use Packagist\WebBundle\Entity\Package;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    /**
     * @Template()
     * @Route("/packages.json", name="packages", defaults={"_format" = "json"})
     */
    public function packagesAction()
    {
        $em = $this->get('doctrine')->getEntityManager();

        gc_enable();

        $packages = $em->getRepository('Packagist\WebBundle\Entity\Package')
            ->getFullPackages();

        $notifyUrl = $this->generateUrl('track_download', array('name' => 'VND/PKG'));

        $data = array(
            'notify' => str_replace('VND/PKG', '%package%', $notifyUrl),
            'packages' => array(),
        );
        foreach ($packages as $package) {
            $versions = array();
            foreach ($package->getVersions() as $version) {
                $versions[$version->getVersion()] = $version->toArray();
                $em->detach($version);
            }
            $data['packages'][$package->getName()] = array($versions);
            $em->detach($package);
        }
        unset($versions, $package, $packages);

        $response = new Response(json_encode($data), 200);
        $response->setSharedMaxAge(120);
        return $response;
    }

    /**
     * @Route("/api/github", name="github_postreceive", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function githubPostReceive(Request $request)
    {
        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload || !isset($payload['repository']['url'])) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Missing or invalid payload',)), 406);
        }

        $username = $request->request->has('username') ?
            $request->request->get('username') :
            $request->query->get('username');

        $apiToken = $request->request->has('apiToken') ?
            $request->request->get('apiToken') :
            $request->query->get('apiToken');

        $doctrine = $this->get('doctrine');
        $user = $doctrine
            ->getRepository('PackagistWebBundle:User')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if (!$user) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials',)), 403);
        }

        if (!preg_match('{github.com/[\w.-]+/[\w.-]+$}', $payload['repository']['url'], $match)) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not parse payload repository URL',)), 406);
        }

        $payloadRepositoryChunk = $match[0];

        foreach ($user->getPackages() as $package) {
            if (false !== strpos($package->getRepository(), $payloadRepositoryChunk)) {
                // We found the package that was referenced.
                $updater = new Updater($doctrine);

                $repository = new VcsRepository(array('url' => $package->getRepository()), new NullIO);
                $package->setAutoUpdated(true);
                $updater->update($package, $repository);

                return new Response('{"status": "success"}', 202);
            }
        }

        return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',)), 404);
    }

    /**
     * @Route("/downloads/{name}", name="track_download", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function trackDownloadAction(Request $request, $name)
    {
        $result = $this->getDoctrine()->getConnection()->fetchAssoc(
            'SELECT p.id, v.id vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.normalizedVersion = ?
            LIMIT 1',
            array($name, $request->request->get('version_normalized'))
        );

        if (!$result) {
            return new Response('{"status": "error", "message": "Package not found"}', 200);
        }

        $redis = $this->get('snc_redis.default');
        $id = $result['id'];
        $version = $result['vid'];

        $throttleKey = 'dl:'.$id.':'.$request->getClientIp().':'.date('Ymd');
        $requests = $redis->incr($throttleKey);
        if (1 === $requests) {
            $redis->expire($throttleKey, 86400);
        }
        if ($requests <= 10) {
            $redis->incr('downloads');

            $redis->incr('dl:'.$id);
            $redis->incr('dl:'.$id.':'.date('Ym'));
            $redis->incr('dl:'.$id.':'.date('Ymd'));

            $redis->incr('dl:'.$id.'-'.$version);
            $redis->incr('dl:'.$id.'-'.$version.':'.date('Ym'));
            $redis->incr('dl:'.$id.'-'.$version.':'.date('Ymd'));
        }

        return new Response('{"status": "success"}', 201);
    }
}
