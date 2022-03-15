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

namespace App\Controller;

use Doctrine\ORM\QueryBuilder;
use App\Entity\Package;
use App\Entity\Version;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Laminas\Feed\Writer\Entry;
use Laminas\Feed\Writer\Feed;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * @author Rafael Dohms <rafael@doh.ms>
 *
 * @Route("/feeds")
 */
class FeedController extends Controller
{
    /**
     * @Route("/", name="feeds")
     */
    public function feedsAction(): Response
    {
        return $this->render('feed/feeds.html.twig');
    }

    /**
     * @Route(
     *     "/packages.{_format}",
     *     name="feed_packages",
     *     requirements={"_format"="(rss|atom)"},
     *     methods={"GET"}
     * )
     */
    public function packagesAction(Request $req): Response
    {
        $repo = $this->doctrine->getRepository(Package::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForNewestPackages()
        );

        $feed = $this->buildFeed(
            $req,
            'Newly Submitted Packages',
            'Latest packages submitted to Packagist.',
            $this->generateUrl('browse', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        return $this->buildResponse($req, $feed);
    }

    /**
     * @Route(
     *     "/releases.{_format}",
     *     name="feed_releases",
     *     requirements={"_format"="(rss|atom)"},
     *     methods={"GET"}
     * )
     */
    public function releasesAction(Request $req): Response
    {
        $repo = $this->doctrine->getRepository(Version::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage()
        );

        $feed = $this->buildFeed(
            $req,
            'New Releases',
            'Latest releases of all packages.',
            $this->generateUrl('browse', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        return $this->buildResponse($req, $feed);
    }

    /**
     * @Route(
     *     "/vendor.{vendor}.{_format}",
     *     name="feed_vendor",
     *     requirements={"_format"="(rss|atom)", "vendor"="[A-Za-z0-9_.-]+"},
     *     methods={"GET"}
     * )
     */
    public function vendorAction(Request $req, string $vendor): Response
    {
        $repo = $this->doctrine->getRepository(Version::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage($vendor)
        );

        $feed = $this->buildFeed(
            $req,
            "$vendor packages",
            "Latest packages updated on Packagist of $vendor.",
            $this->generateUrl('view_vendor', ['vendor' => $vendor], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        return $this->buildResponse($req, $feed);
    }

    /**
     * @Route(
     *     "/package.{package}.{_format}",
     *     name="feed_package",
     *     requirements={"_format"="(rss|atom)", "package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"},
     *     methods={"GET"}
     * )
     */
    public function packageAction(Request $req, string $package): Response
    {
        $repo = $this->doctrine->getRepository(Version::class);
        $packages = $this->getLimitedResults(
            $repo->getQueryBuilderForLatestVersionWithPackage(null, $package)
        );

        $feed = $this->buildFeed(
            $req,
            "$package releases",
            "Latest releases on Packagist of $package.",
            $this->generateUrl('view_package', ['name' => $package], UrlGeneratorInterface::ABSOLUTE_URL),
            $packages
        );

        $response = $this->buildResponse($req, $feed);

        $first = reset($packages);
        if ($first instanceof Version && $first->getReleasedAt()) {
            $response->setDate($first->getReleasedAt());
        }

        return $response;
    }

    /**
     * Limits a query to the desired number of results
     * @return iterable<Package>|iterable<Version>
     */
    protected function getLimitedResults(QueryBuilder $queryBuilder): iterable
    {
        $query = $queryBuilder
            ->getQuery()
            ->setMaxResults(40);

        return $query->getResult();
    }

    /**
     * Builds the desired feed
     *
     * @param iterable<Package|Version> $items
     */
    protected function buildFeed(Request $req, string $title, string $description, string $url, iterable $items): Feed
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

        if ($req->getRequestFormat() === 'atom') {
            $feed->setFeedLink(
                $req->getUri(),
                $req->getRequestFormat()
            );
        }

        if ($feed->count()) {
            $feed->setDateModified($feed->getEntry(0)->getDateModified());
        } else {
            $feed->setDateModified(new \DateTime());
        }

        return $feed;
    }

    /**
     * Receives either a Package or a Version and populates a feed entry.
     *
     * @param Package|Version $item
     */
    protected function populateEntry(Entry $entry, $item): void
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
     */
    protected function populatePackageData(Entry $entry, Package $package): void
    {
        $entry->setTitle($package->getName());
        $entry->setLink(
            $this->generateUrl(
                'view_package',
                ['name' => $package->getName()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        );
        $entry->setId($package->getName());

        $entry->setDateModified($package->getCreatedAt());
        $entry->setDateCreated($package->getCreatedAt());
        $entry->setDescription($package->getDescription() ?: ' ');
    }

    /**
     * Populates a feed entry with data coming from Version objects.
     *
     * @param Version                 $version
     */
    protected function populateVersionData(Entry $entry, Version $version): void
    {
        $entry->setTitle($entry->getTitle()." ({$version->getVersion()})");
        $entry->setId($entry->getId().' '.$version->getVersion());

        $entry->setDateModified($version->getReleasedAt());
        $entry->setDateCreated($version->getReleasedAt());

        foreach ($version->getAuthors() as $author) {
            if (!empty($author['name'])) {
                $entry->addAuthor([
                    'name' => $author['name']
                ]);
            }
        }
    }

    /**
     * Creates a HTTP Response and exports feed
     */
    protected function buildResponse(Request $req, Feed $feed): Response
    {
        $format = $req->getRequestFormat();

        if (null === $format) {
            throw new \RuntimeException('Request format is not set.');
        }

        $content = $feed->export($format);

        $response = new Response($content, 200);
        $response->setSharedMaxAge(3600);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
