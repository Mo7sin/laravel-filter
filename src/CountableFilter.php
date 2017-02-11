<?php
namespace Czim\Filter;

use Czim\Filter\Contracts\CountableFilterInterface;
use Czim\Filter\Contracts\FilterDataInterface;
use Czim\Filter\Contracts\ParameterCounterInterface;
use Czim\Filter\Exceptions\FilterParameterUnhandledException;
use Czim\Filter\Exceptions\ParameterStrategyInvalidException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use ReflectionClass;

/**
 * The point is to get an overview of things that may be alternatively filtered
 * for if *only* that particular attribute is altered. If you're filtering by,
 * say, product line and brand, then this should look up:
 *      the number of matches for all brands that also match the product line filter,
 *      and the number of matches for all product lines that also match the brand filter
 */
abstract class CountableFilter extends Filter implements CountableFilterInterface
{
    /**
     * Which filter parameters are 'countable' -- (should) have implementations
     * for the getCounts() method. This is what's used to determine which other
     * filter options (f.i. brands, product lines) to show for the current selection
     *
     * @var string[]
     */
    protected $countables = [];

    /**
     * Application strategies for all countables to get counts for.
     * Just like the strategies property, but now for getCount()
     *
     * These can be either:
     *      an instance of ParameterCounterFilter,
     *      a string classname of an instantiatable ParameterCounterFilter,
     *      a callback that follows the same logic as ParameterCounterFilter->count()
     *      null, which means that getCountForParameter() will be called on the Filter
     *          itself, which MUST then be able to handle it!
     *
     * @var array   associative
     */
    protected $countStrategies = [];

    /**
     * List of countables that should not be included in getCount() results.
     *
     * @var string[]
     */
    protected $ignoreCountables = [];

    /**
     * Returns new base query object to build countable query on.
     * This will be called for each countable parameter, and could be
     * something like: EloquentModelName::query();
     *
     * @param string $parameter     name of the countable parameter
     * @return EloquentBuilder
     */
    abstract protected function getCountableBaseQuery($parameter = null);


    /**
     * Constructs the relevant FilterData if one is not injected
     *
     * @param array|Arrayable|FilterDataInterface $data
     */
    public function __construct($data)
    {
        parent::__construct($data);

        $this->countStrategies = $this->countStrategies();
    }

    /**
     * Sets initial strategies for counting countables
     * Override this to set the countable strategies for your filter.
     *
     * @return array
     */
    protected function countStrategies()
    {
        return [];
    }

    /**
     * Returns a list of the countable parameters to get counts for
     *
     * @return array
     */
    public function getCountables()
    {
        return $this->countables;
    }

    /**
     * Returns a list of the countable parameters that are not ignored
     *
     * @return array
     */
    protected function getActiveCountables()
    {
        return array_diff($this->getCountables(), $this->ignoreCountables);
    }

    /**
     * Gets alternative counts per (relevant) attribute for the filter data.
     *
     * @param array $countables     overrides ignoredCountables
     * @return CountableResults
     * @throws ParameterStrategyInvalidException
     */
    public function getCounts($countables = [])
    {
        $counts = new CountableResults;

        $strategies = $this->buildCountableStrategies();

        // determine which countables to count for
        $countables = ( ! empty($countables))
                        ?   array_intersect($this->getCountables(), $countables)
                        :   $this->getActiveCountables();

        foreach ($countables as $parameterName) {

            // should we skip it no matter what?
            if ($this->isCountableIgnored($parameterName)) continue;

            $strategy = isset($strategies[$parameterName]) ? $strategies[$parameterName] : null;

            // normalize the strategy so that we can call_user_func on it
            if (is_a($strategy, ParameterCounterInterface::class)) {

                $strategy = [ $strategy, 'count' ];

            } elseif (is_null($strategy)) {
                // default, let it be handled by applyParameter

                $strategy = [ $this, 'countParameter' ];

            } elseif ( ! is_callable($strategy)) {

                throw new ParameterStrategyInvalidException(
                    "Invalid counting strategy defined for parameter '{$parameterName}',"
                    . " must be ParameterFilterInterface, classname, callable or null"
                );
            }

            // start with a fresh query
            $query = $this->getCountableBaseQuery();

            // apply the filter while temporarily ignoring the current countable parameter
            $this->ignoreParameter($parameterName);
            $this->apply($query);
            $this->unignoreParameter($parameterName);

            // retrieve the count and put it in the results
            $counts->put($parameterName, call_user_func_array($strategy, [$parameterName, $query, $this]));
        }

        return $counts;
    }

    /**
     * Get count result for a parameter's records, given the filter settings for other parameters.
     * this is the fall-back for when no other strategy is configured in $this->countStrategies.
     *
     * Override this if you need to use it in a specific Filter instance
     *
     * @param string          $parameter countable name
     * @param EloquentBuilder $query
     * @return mixed
     * @throws FilterParameterUnhandledException
     */
    protected function countParameter($parameter, $query)
    {
        // default is to always warn that we don't have a strategy
        throw new FilterParameterUnhandledException("No fallback strategy determined for for countable parameter '{$parameter}'");
    }

    /**
     * Builds up the strategies so that all instantiatable strategies are instantiated
     *
     * @return array
     * @throws ParameterStrategyInvalidException
     */
    protected function buildCountableStrategies()
    {
        foreach ($this->countStrategies as &$strategy) {

            // check if the strategy is a string that should be instantiated as a class
            if (is_string($strategy)) {

                try {

                    $reflection = new ReflectionClass($strategy);

                    if ( ! $reflection->isInstantiable()) {
                        throw new ParameterStrategyInvalidException("Uninstantiable string provided as countStrategy for '{$strategy}'");
                    }

                    $strategy = new $strategy();

                } catch (\Exception $e) {

                    throw new ParameterStrategyInvalidException(
                        "Exception thrown while trying to reflect or instantiate string provided as countStrategy for '{$strategy}'",
                        0, $e
                    );
                }

                // check if it is of the correct type
                if ( ! is_a($strategy, ParameterCounterInterface::class)) {

                    throw new ParameterStrategyInvalidException(
                        "Instantiated string provided is not a ParameterFilter: '" . get_class($strategy) . "'"
                    );
                }
            }
        }

        unset($strategy);

        return $this->countStrategies;
    }

    /**
     * Disables one or more countables when getCounts() is invoked.
     *
     * Note that this differs from ignoreParameter in that the count itself is omitted, but it does not
     * affect what parameters get applied to the queries for the other countables!
     *
     * @param string|array $countable
     * @return $this
     */
    public function ignoreCountable($countable)
    {
        if ( ! is_array($countable)) $countable = [ $countable];

        $this->ignoreCountables = array_merge($this->ignoreCountables, $countable);

        return $this;
    }

    /**
     * Re-enables one or more countables when getCounts() is invoked
     *
     * @param string|array $countable
     * @return $this
     */
    public function unignoreCountable($countable)
    {
        if ( ! is_array($countable)) $countable = [ $countable];

        $this->ignoreCountables = array_diff($this->ignoreCountables, $countable);

        return $this;
    }

    /**
     * Returns whether a given countable is currently being ignored/omitted
     *
     * @param string $countableName
     * @return bool
     */
    public function isCountableIgnored($countableName)
    {
        if (empty($this->ignoreCountables)) return false;

        return (array_search($countableName, $this->ignoreCountables) !== false);
    }

}
