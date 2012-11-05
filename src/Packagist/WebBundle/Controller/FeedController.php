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

use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Zend\Feed\Writer\Entry;
use Zend\Feed\Writer\Feed;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * @author Rafael Dohms <rafael@doh.ms>
 *
 * @Route("/feeds")
 */
class FeedController extends Controller
{
    /**
     * @Route("/", name="feeds")
     * @Template
     */
    public function feedsAction()
    {
        return array();
    }

    /**
     * @Route(
     *     "/packages.{_format}",
     *     name="feed_packages",
     *     requirements={"_format"="(rss|atom)"}
     * )
     * @Method({"GET"})
     */
    public function packagesAction()
    {
        /** @var $repo \Packagist\WebBundle\Entity\VersionRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForNewestPackages()
        );

        $feed = $this->buildFeed(
            'Newly Submitted Packages',
            'Latest packages submitted to Packagist.',
            $this->generateUrl('browse', array(), true),
            $packages
        );

        return $this->buildResponse($feed);
    }

    /**
     * @Route(
     *     "/releases.{_format}",
     *     name="feed_releases",
     *     requirements={"_format"="(rss|atom)"}
     * )
     * @Method({"GET"})
     */
    public function releasesAction()
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage()
        );

        $feed = $this->buildFeed(
            'New Releases',
            'Latest releases of all packages.',
            $this->generateUrl('browse', array(), true),
            $packages
        );

        return $this->buildResponse($feed);
    }

    /**
     * @Route(
     *     "/vendor.{vendor}.{_format}",
     *     name="feed_vendor",
     *     requirements={"_format"="(rss|atom)", "vendor"="[A-Za-z0-9_.-]+"}
     * )
     * @Method({"GET"})
     */
    public function vendorAction($vendor)
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage($vendor)
        );

        $feed = $this->buildFeed(
            "$vendor packages",
            "Latest packages updated on Packagist of $vendor.",
            $this->generateUrl('view_vendor', array('vendor' => $vendor), true),
            $packages
        );

        return $this->buildResponse($feed);
    }

    /**
     * @Route(
     *     "/package.{package}.{_format}",
     *     name="feed_package",
     *     requirements={"_format"="(rss|atom)", "package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}
     * )
     * @Method({"GET"})
     */
    public function packageAction($package)
    {
        /** @var $repo \Packagist\WebBundle\Entity\PackageRepository */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage(null, $package)
        );

        $feed = $this->buildFeed(
            "$package releases",
            "Latest releases on Packagist of $package.",
            $this->generateUrl('view_package', array('name' => $package), true),
            $packages
        );

        return $this->buildResponse($feed);
    }

    /**
     * Limits a query to the desired number of results
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     *
     * @return array|\Traversable
     */
    protected function getLimitedResults(QueryBuilder $queryBuilder)
    {
        $paginator = new Pagerfanta(new DoctrineORMAdapter($queryBuilder));
        $paginator->setMaxPerPage(
            $this->container->getParameter('packagist_web.rss_max_items')
        );
        $paginator->setCurrentPage(1);

        return $paginator->getCurrentPageResults();
    }

    /**
     * Builds the desired feed
     *
     * @param string $title
     * @param string $description
     * @param array  $items
     *
     * @return \Zend\Feed\Writer\Feed
     */
    protected function buildFeed($title, $description, $url, $items)
    {
        $feed = new Feed();
        $feed->setTitle($title);
        $feed->setDescription($description);
        $feed->setLink($url);
        $feed->setGenerator('Packagist');

        foreach ($items as $item) {
            $entry = $feed->createEntry();
            $this->populateEntry($entry, $item);
            $feed->addEntry($entry);
        }

        if ($this->getRequest()->getRequestFormat() == 'atom') {
            $feed->setFeedLink(
                $this->getRequest()->getUri(),
                $this->getRequest()->getRequestFormat()
            );
        }

        if ($feed->count()) {
            $feed->setDateModified($feed->getEntry(0)->getDateModified());
        }

        return $feed;
    }

    /**
     * Receives either a Package or a Version and populates a feed entry.
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Package|Version         $item
     */
    protected function populateEntry(Entry $entry, $item)
    {
        if ($item instanceof Package) {
            $this->populatePackageData($entry, $item);
        } elseif ($item instanceof Version) {
            $this->populatePackageData($entry, $item->getPackage());
            $this->populateVersionData($entry, $item);
        }
    }

    /**
     * Populates a feed entry with data coming from Package objects.
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Package                 $package
     */
    protected function populatePackageData(Entry $entry, Package $package)
    {
        $entry->setTitle($package->getName());
        $entry->setLink(
            $this->generateUrl(
                'view_package',
                array('name' => $package->getName()),
                true
            )
        );

        $entry->setDateModified($package->getCreatedAt());
        $entry->setDateCreated($package->getCreatedAt());
        $entry->setDescription($package->getDescription() ?: ' ');
    }

    /**
     * Populates a feed entry with data coming from Version objects.
     *
     * @param \Zend\Feed\Writer\Entry $entry
     * @param Version                 $version
     */
    protected function populateVersionData(Entry $entry, Version $version)
    {
        $entry->setTitle($entry->getTitle()." ({$version->getVersion()})");

        $entry->setDateModified($version->getReleasedAt());
        $entry->setDateCreated($version->getReleasedAt());

        foreach ($version->getAuthors() as $author) {
            /** @var $author \Packagist\WebBundle\Entity\Author */
            if ($author->getName()) {
                $entry->addAuthor(array(
                    'name' => $author->getName()
                ));
            }
        }
    }

    /**
     * Creates a HTTP Response and exports feed
     *
     * @param \Zend\Feed\Writer\Feed $feed
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function buildResponse(Feed $feed)
    {
        $content = $feed->export($this->getRequest()->getRequestFormat());

        $response = new Response($content, 200);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
