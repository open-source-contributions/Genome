<?php
declare(strict_types = 1);

namespace Innmind\Genome\Loader;

use Innmind\Genome\{
    Loader,
    Genome,
    Gene,
    Exception\DomainException,
};
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\HttpTransport\Transport;
use Innmind\Http\{
    Message\Request\Request,
    Message\Method,
    ProtocolVersion,
};
use Innmind\Json\Json;
use Innmind\Immutable\{
    Map,
    Str,
    Sequence,
};
use function Innmind\Immutable\unwrap;
use Composer\Semver\{
    VersionParser,
    Semver,
};

final class Packagist implements Loader
{
    private $fulfill;

    public function __construct(Transport $fulfill)
    {
        $this->fulfill = $fulfill;
    }

    public function __invoke(Path $path): Genome
    {
        return Genome::defer($this->load($path));
    }

    /**
     * @return \Generator<Gene>
     */
    private function load(Path $path): \Generator
    {
        $name = Str::of($path->toString())->leftTrim('/')->toString();
        $url = "https://packagist.org/search.json?q=$name/";
        $results = [];

        do {
            $request = new Request(
                Url::of($url),
                Method::get(),
                new ProtocolVersion(2, 0),
            );
            $response = ($this->fulfill)($request);
            /** @var array{results: list<array{name: string, virtual?: bool}>, total: int, next?: string} */
            $content = Json::decode($response->body()->toString());
            $results = \array_merge($results, $content['results']);
            $url = $content['next'] ?? null;
        } while (!\is_null($url));

        foreach ($results as $result) {
            if (!Str::of($result['name'])->matches("~^$name/~")) {
                continue;
            }

            if ($result['virtual'] ?? false === true) {
                continue;
            }

            $request = new Request(
                Url::of("https://packagist.org/packages/{$result['name']}.json"),
                Method::get(),
                new ProtocolVersion(2, 0),
            );
            $response = ($this->fulfill)($request);
            /** @var array{package: array{versions: array<string, array{abandoned?: bool, type: string, name: string, bin?: list<string>, extra?: array{gene?: array{expression?: list<string>, mutation?: list<string>, suppression?: list<string>}}}>}} */
            $body = Json::decode($response->body()->toString());
            $content = $body['package'];

            try {
                yield $this->geneOf($content);
            } catch (DomainException $e) {
                continue;
            }
        }
    }

    /**
     * @param array{versions: array<string, array{abandoned?: bool, type: string, name: string, bin?: list<string>, extra?: array{gene?: array{expression?: list<string>, mutation?: list<string>, suppression?: list<string>}}}>} $package
     */
    private function geneOf(array $package): Gene
    {
        $versions = $package['versions'];
        /** @var Map<string, array{abandoned?: bool, type: string, name: string, bin?: list<string>, extra?: array{gene?: array{expression?: list<string>, mutation?: list<string>, suppression?: list<string>}}}> */
        $published = Map::of('string', 'array');

        foreach ($versions as $key => $value) {
            $published = ($published)($key, $value);
        }

        $published = $published
            ->filter(static function(string $version): bool {
                return VersionParser::parseStability($version) === 'stable';
            })
            ->filter(static function(string $_, array $version): bool {
                return !($version['abandoned'] ?? false);
            });

        if ($published->empty()) {
            throw new DomainException;
        }

        /** @var list<string> */
        $versions = Semver::rsort(unwrap($published->keys()));

        $latest = $published->get($versions[0]);

        if ($latest['type'] === 'project') {
            return Gene::template(
                new Gene\Name($latest['name']),
                Sequence::strings(...($latest['extra']['gene']['expression'] ?? [])),
                Sequence::strings(...($latest['extra']['gene']['mutation'] ?? [])),
                Sequence::strings(...($latest['extra']['gene']['suppression'] ?? [])),
            );
        }

        if (
            $latest['type'] === 'library' &&
            isset($latest['bin'])
        ) {
            return Gene::functional(
                new Gene\Name($latest['name']),
                Sequence::strings(...($latest['extra']['gene']['expression'] ?? [])),
                Sequence::strings(...($latest['extra']['gene']['mutation'] ?? [])),
                Sequence::strings(...($latest['extra']['gene']['suppression'] ?? [])),
            );
        }

        throw new DomainException;
    }
}
