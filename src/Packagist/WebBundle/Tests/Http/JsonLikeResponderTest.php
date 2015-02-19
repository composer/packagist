<?php


namespace Packagist\WebBundle\Tests\Http;


use Packagist\WebBundle\Http\JsonLikeResponder;
use Symfony\Component\HttpFoundation\Request;

class JsonLikeResponderTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @param $format
     * @param $expectedOutput
     * @dataProvider provideFormats
     */
    public function testIsJsonLikeRequest($format, $expectedOutput)
    {
        $request = new Request();
        $request->setRequestFormat($format);

        $this->assertEquals($expectedOutput, JsonLikeResponder::isJsonLikeRequest($request));
    }

    public function provideFormats()
    {
        return array(
            array('json', true),
            array('jsonp', true),
            array('xml', false),
            array('JSON', true),
            array('JSONP', true),
        );
    }

    /**
     * @expectedException \Exception
     */
    public function testCreateResponseWithNotJson()
    {
        $request = new Request();
        $request->setRequestFormat('xml');

        $response = JsonLikeResponder::createResponse($request, array('data' => 'nice'), 200);
    }

    public function testCreateResponseWithSimpleJson()
    {
        $request = new Request();
        $request->setRequestFormat('json');

        $response = JsonLikeResponder::createResponse($request, array('data' => 'nice'), 200);

        $this->assertEquals('{"data":"nice"}', $response->getContent());
    }

    /**
     * @expectedException \Exception
     */
    public function testCreateResponseWithJsonpWithoutCallback()
    {
        $request = new Request();
        $request->setRequestFormat('jsonp');

        $response = JsonLikeResponder::createResponse($request, array('data' => 'nice'), 200);
    }

    public function testCreateResponseWithJsonp()
    {
        $request = new Request();
        $request->setRequestFormat('jsonp');
        $request->query->set('callback', 'call_this');

        $response = JsonLikeResponder::createResponse($request, array('data' => 'nice'), 200);

        $this->assertEquals('/**/call_this({"data":"nice"});', $response->getContent());
    }


}
