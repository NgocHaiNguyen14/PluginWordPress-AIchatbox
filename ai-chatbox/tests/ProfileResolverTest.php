<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-profiles.php';
require_once __DIR__ . '/../includes/class-profile-resolver.php';

final class ProfileResolverTest extends TestCase
{
    private function profiles(): array
    {
        return [
            ['id' => 1, 'path_prefix' => '', 'is_default' => true, 'assistant_name' => 'Default Bot'],
            ['id' => 2, 'path_prefix' => '/members', 'is_default' => false, 'assistant_name' => 'Members Bot'],
            ['id' => 3, 'path_prefix' => '/members/harry', 'is_default' => false, 'assistant_name' => 'Harry Bot'],
        ];
    }

    public function test_resolves_longest_matching_prefix(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members/harry/notes', $this->profiles());

        $this->assertSame('Harry Bot', $result['assistant_name']);
    }

    public function test_resolves_shorter_prefix_when_longer_one_does_not_match(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members/james', $this->profiles());

        $this->assertSame('Members Bot', $result['assistant_name']);
    }

    public function test_falls_back_to_default_when_no_prefix_matches(): void
    {
        $result = AICB_Profile_Resolver::resolve('/blog/hello-world', $this->profiles());

        $this->assertSame('Default Bot', $result['assistant_name']);
    }

    public function test_strips_trailing_slash_before_matching(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members/harry/', $this->profiles());

        $this->assertSame('Harry Bot', $result['assistant_name']);
    }

    public function test_empty_profiles_list_returns_pure_defaults(): void
    {
        $result = AICB_Profile_Resolver::resolve('/anything', []);

        $this->assertSame(AICB_Profiles::get_defaults()['assistant_name'], $result['assistant_name']);
    }

    public function test_does_not_match_prefix_as_substring_of_a_different_path(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members2/foo', $this->profiles());

        $this->assertSame('Default Bot', $result['assistant_name']);
    }
}
