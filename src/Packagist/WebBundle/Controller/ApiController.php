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

        $data = array();
        foreach ($packages as $package) {
            $versions = array();
            foreach ($package->getVersions() as $version) {
                $versions[$version->getVersion()] = $version->toArray();
                $em->detach($version);
            }
            $data[$package->getName()] = array('versions' => $versions);
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
}
