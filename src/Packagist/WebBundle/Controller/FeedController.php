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
use Composer\Factory;
use Composer\Package\Loader\ArrayLoader;
use Packagist\WebBundle\Package\Updater;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @author Rafael Dohms <rafael@doh.ms>
 *
 * @Route("/feed")
 */
class FeedController extends Controller
{
    /**
     * @Route("/", name="feed_home")
     */
    public function indexAction()
    {
        return $this->forward('PackagistFeedController:Feed:latest');
    }

    /**
     * @Route(
     *     "/latest.{format}",
     *     name="feed_latest",
     *     requirements={"format"="(rss|atom)"},
     *     defaults={"format"="rss"}
     * )
     * @Method({"GET"})
     */
    public function latestAction($format)
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $packages = $repo->getLatestPackages();

        $feed = $this->buildFeed('Latest Packages', 'Latest packages updated on Packagist.', $packages, $format);

        return $this->buildResponse($feed, $format);
    }

    /**
     * @Route(
     *     "/newest.{format}",
     *     name="feed_newest",
     *     requirements={"format"="(rss|atom)"},
     *     defaults={"format"="rss"}
     * )
     * @Method({"GET"})
     */
    public function newestAction($format)
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $packages = $repo->getNewestPackages();

        $feed = $this->buildFeed('Newest Packages', 'Latest packages added to Packagist.', $packages, $format);

        return $this->buildResponse($feed, $format);
    }

    /**
     * @Route(
     *     "/vendor.{filter}.{format}",
     *     name="feed_vendor",
     *     requirements={"format"="(rss|atom)"},
     *     defaults={"format"="rss"}
     * )
     * @Method({"GET"})
     */
    public function vendorAction($filter, $format)
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $packages = $repo->getLatestPackagesByVendor($filter);

        $feed = $this->buildFeed("$filter Packages", "Latest packages updated on Packagist for $filter.", $packages, $format);

        return $this->buildResponse($feed, $format);
    }

    /**
     * Builds the desired feed
     *
     * @param string $title
     * @param string $description
     * @param array $packages
     * @param string $format
     *
     * @return \Zend\Feed\Writer\Feed
     */
    protected function buildFeed($title, $description, $packages, $format)
    {
        $feed = new \Zend\Feed\Writer\Feed();
        $feed->setTitle($title);
        $feed->setDescription($description);
        $feed->setLink($this->getRequest()->getSchemeAndHttpHost());
        $feed->setDateModified(time());

        foreach ($packages as $package) {
            $entry = $feed->createEntry();
            $this->populatePackageEntry($entry, $package);

            $feed->addEntry($entry);
        }

        if ($format == 'atom'){
            $feed->setFeedLink($this->getRequest()->getUri(), $format);
        }

        return $feed;
    }

    /**
     * Creates a new feed entry from the selected package
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Package $package
     *
     * @return void
     */
    protected function populatePackageEntry($entry, $package)
    {
        /** @var $version Version */
        $version = $package->getVersions()->first() ?: new Version();

        $entry->setTitle("{$package->getPackageName()} {$version->getVersion()}");
        $entry->setLink($this->generateUrl('view_package', array('name' => $package->getName()), true));

        $entry->setDateModified($package->getUpdatedAt());
        $entry->setDateCreated($package->getCreatedAt());
        $entry->setDescription($package->getDescription());

        foreach ($version->getAuthors() as $author) {
            /** @var $author \Packagist\WebBundle\Entity\Author */
            $entry->addAuthor(array(
                'name'  => $author->getName()
            ));
        }
    }

    /**
     * Creates a HTTP Response and exports feed
     * 
     * @param \Zend\Feed\Writer\Feed $feed
     * @param string $format
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function buildResponse($feed, $format)
    {
        $content = $feed->export($format);
        $lastContent = $feed->getEntry(0);
        $etag = md5($content);

        $response = new Response($content, 200, array('Content-Type' => "application/$format+xml"));
        $response->setEtag($etag);
        $response->setLastModified($lastContent->getDateModified());

        return $response;
    }
}
