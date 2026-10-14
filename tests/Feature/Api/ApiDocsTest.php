<?php

namespace Tests\Feature\Api;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_swagger_ui_page_is_public(): void
    {
        $this->get('/api/docs')->assertOk()->assertSee('swagger-ui', false)->assertSee('/api/docs/openapi.yaml', false);
    }

    public function test_openapi_spec_is_valid_yaml_and_documents_create_payment(): void
    {
        $response = $this->get('/api/docs/openapi.yaml')->assertOk();

        $spec = Yaml::parse($response->getContent());

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('post', $spec['paths']['/api/create-payment']);
        $this->assertSame(['pix', 'credit_card'], $spec['components']['schemas']['CreatePaymentRequest']['properties']['payment_methods']['items']['enum']);
    }

    public function test_spec_required_fields_match_request_rules(): void
    {
        $spec = Yaml::parse(file_get_contents(base_path('docs/api/openapi.yaml')));

        $this->assertEqualsCanonicalizing(['refExternal', 'items', 'customer'], $spec['components']['schemas']['CreatePaymentRequest']['required']);
    }
}
