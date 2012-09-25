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
 * @Route("/feeds")
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
     *     "/packages.{format}",
     *     name="feed_packages",
     *     requirements={"format"="(rss|atom)"},
     *     defaults={"format"="rss"}
     * )
     * @Method({"GET"})
     */
    public function packagesAction($format)
    {
        /** @var $repo \Packagist\WebBundle\Entity\VersionRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
        $packages = $repo->getLatestVersionWithPackage(
            $this->container->getParameter('packagist_web.rss_max_items')
        );

        $feed = $this->buildFeed(
            'Latest Packages',
            'Latest packages updated on Packagist.',
            $packages,
            $format
        );

        return $this->buildResponse($feed, $format);
    }

    /**
     * @Route(
     *     "/releases.{format}",
     *     name="feed_releases",
     *     requirements={"format"="(rss|atom)"},
     *     defaults={"format"="rss"}
     * )
     * @Method({"GET"})
     */
    public function releasesAction($format)
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $packages = $repo->getNewestPackages(
            $this->container->getParameter('packagist_web.rss_max_items')
        );

        $feed = $this->buildFeed(
            'Latest Released Packages',
            'Latest packages added to Packagist.',
            $packages,
            $format
        );

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
        $packages = $repo->getLatestPackagesByVendor(
            $filter,
            $this->container->getParameter('packagist_web.rss_max_items')
        );

        $feed = $this->buildFeed(
            "$filter Packages",
            "Latest packages updated on Packagist for $filter.",
            $packages,
            $format
        );

        return $this->buildResponse($feed, $format);
    }

    /**
     * Builds the desired feed
     *
     * @param string $title
     * @param string $description
     * @param array $items
     * @param string $format
     *
     * @return \Zend\Feed\Writer\Feed
     */
    protected function buildFeed($title, $description, $items, $format)
    {
        $feed = new \Zend\Feed\Writer\Feed();
        $feed->setTitle($title);
        $feed->setDescription($description);
        $feed->setLink($this->getRequest()->getSchemeAndHttpHost());
        $feed->setDateModified(time());

        foreach ($items as $item) {
            $entry = $feed->createEntry();
            $this->populateEntry($entry, $item);
            $feed->addEntry($entry);
        }

        if ($format == 'atom'){
            $feed->setFeedLink($this->getRequest()->getUri(), $format);
        }

        return $feed;
    }

    /**
     * Receives either a Package or a Version and populates a feed entry.
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Package|Version $item
     */
    protected function populateEntry($entry, $item)
    {
        if ($item instanceof Package) {
            $version = $item->getVersions()->first() ?: new Version();

            $this->populatePackageData($entry, $item);
            $this->populateVersionData($entry, $version);
        }

        if ($item instanceof Version) {
            $this->populatePackageData($entry, $item->getPackage());
            $this->populateVersionData($entry, $item);
        }
    }

    /**
     * Populates a feed entry with data coming from Package objects.
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Package $package
     */
    protected function populatePackageData($entry, $package)
    {
        $entry->setTitle($package->getPackageName());
        $entry->setLink(
            $this->generateUrl(
                'view_package',
                array('name' => $package->getName()),
                true
            )
        );

        $entry->setDateModified($package->getUpdatedAt());
        $entry->setDateCreated($package->getCreatedAt());
        $entry->setDescription($package->getDescription());
    }

    /**
     * Populates a feed entry with data coming from Version objects.
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Version $version
     */
    protected function populateVersionData($entry, $version)
    {
        $entry->setTitle($entry->getTitle()." ({$version->getVersion()})");

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
        $etag = md5($content);
        $headers = array('Content-Type' => "application/$format+xml");

        $response = new Response($content, 200, $headers);
        $response->setEtag($etag);
        $response->setSharedMaxAge(3600);

        if ($feed->count() > 0) {
            $response->setLastModified($feed->getEntry(0)->getDateModified());
        }

        return $response;
    }
}
